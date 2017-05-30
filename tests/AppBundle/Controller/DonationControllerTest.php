<?php

namespace Tests\AppBundle\Controller;

use AppBundle\Donation\PayboxPaymentSubscription;
use AppBundle\Entity\Donation;
use AppBundle\Mailjet\Message\DonationMessage;
use AppBundle\Repository\DonationRepository;
use Goutte\Client as PayboxClient;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\SqliteWebTestCase;

/**
 * @group functional
 */
class DonationControllerTest extends SqliteWebTestCase
{
    use ControllerTestTrait;

    /* @var Client */
    private $appClient;

    /* @var PayboxClient */
    private $payboxClient;

    /* @var DonationRepository */
    private $donationRepository;

    public function getFrequenciesDonation(): array
    {
        $frequencies = PayboxPaymentSubscription::DONATION_FREQUENCIES;
        $frequencies[] = 1;
        $data = [];
        foreach ($frequencies as $frequency) {
            $data[] = [$frequency];
        }

        return $data;
    }

    /**
     * @dataProvider getFrequenciesDonation
     */
    public function testFullProcess(int $frequency)
    {
        $appClient = $this->appClient;
        // There should not be any donation for the moment
        $this->assertCount(0, $this->donationRepository->findAll());

        $crawler = $appClient->request(Request::METHOD_GET, sprintf('/don/coordonnees?montant=30&frequence=%s', $frequency));

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $this->appClient->submit($crawler->filter('form[name=app_donation]')->form([
            'app_donation' => [
                'gender' => 'male',
                'lastName' => 'Doe',
                'firstName' => 'John',
                'emailAddress' => 'test@paybox.com',
                'address' => '9 rue du Lycée',
                'country' => 'FR',
                'postalCode' => '06000',
                'cityName' => 'Nice',
                'phone' => [
                    'country' => 'FR',
                    'number' => '04 01 02 03 04',
                ],
            ],
        ]));

        // Donation should have been saved
        $this->assertCount(1, $donations = $this->donationRepository->findAll());
        $this->assertInstanceOf(Donation::class, $donation = $donations[0]);

        /* @var Donation $donation */
        $this->assertEquals(3000, $donation->getAmount());
        $this->assertSame('male', $donation->getGender());
        $this->assertSame('Doe', $donation->getLastName());
        $this->assertSame('John', $donation->getFirstName());
        $this->assertSame('test@paybox.com', $donation->getEmailAddress());
        $this->assertSame('FR', $donation->getCountry());
        $this->assertSame('06000', $donation->getPostalCode());
        $this->assertSame('Nice', $donation->getCityName());
        $this->assertSame('9 rue du Lycée', $donation->getAddress());
        $this->assertSame(33, $donation->getPhone()->getCountryCode());
        $this->assertSame('401020304', $donation->getPhone()->getNationalNumber());
        $this->assertSame($frequency, $donation->getDuration());

        // Email should not have been sent
        $this->assertCount(0, $this->getMailjetEmailRepository()->findMessages(DonationMessage::class));

        // We should be redirected to payment
        $this->assertClientIsRedirectedTo(sprintf('/don/%s/paiement', $donation->getUuid()->toString()), $appClient);

        $crawler = $appClient->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $formNode = $crawler->filter('input[name=PBX_CMD]');

        if ($suffix = PayboxPaymentSubscription::fromInteger($donation->getDuration())->getPayboxSuffixCmd($donation->getAmount())) {
            $this->assertContains($suffix, $formNode->attr('value'));
        }

        /*
         * En-Marche payment page (verification and form to Paybox)
         */
        $formNode = $crawler->filter('form[name=app_donation_payment]');

        $this->assertSame('https://preprod-tpeweb.paybox.com/cgi/MYchoix_pagepaiement.cgi', $formNode->attr('action'));

        $formTime = time();
        $crawler = $this->payboxClient->submit($formNode->form());

        /*
         * Paybox redirection and payment form
         */
        $crawler = $this->payboxClient->submit($crawler->filter('form[name=PAYBOX]')->form());

        // Pay using a testing account
        $crawler = $this->payboxClient->submit($crawler->filter('form[name=form_pay]')->form([
            'NUMERO_CARTE' => '1111222233334444',
            'MOIS_VALIDITE' => '12',
            'AN_VALIDITE' => '32',
            'CVVX' => '123',
        ]));

        $content = $this->payboxClient->getInternalResponse()->getContent();

        // Check payment was successful
        $expectedCount = PayboxPaymentSubscription::DEFAULT_FREQUENCY === $donation->getDuration() ? PayboxPaymentSubscription::DEFAULT_FREQUENCY : 2;
        $this->assertSame($expectedCount, $crawler->filter('td:contains("30.00 EUR")')->count());
        $this->assertContains('Paiement r&eacute;alis&eacute; avec succ&egrave;s', $content);

        /*
         * Emulate IPN callback to Symfony
         */
        $mockUrl = $crawler->filter('a')->first()->attr('href');
        $ipnUrl = str_replace('https://httpbin.org/status/200', '/don/payment-ipn/'.$formTime, $mockUrl);

        $appClient->request(Request::METHOD_GET, $ipnUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        // Donation should have been completed
        $this->getEntityManager(Donation::class)->refresh($donation);

        $this->assertTrue($donation->isFinished());
        $this->assertNotNull($donation->getDonatedAt());
        $this->assertSame('00000', $donation->getPayboxResultCode());
        $this->assertSame('XXXXXX', $donation->getPayboxAuthorizationCode());

        // Email should have been sent
        $this->assertCount(1, $this->getMailjetEmailRepository()->findMessages(DonationMessage::class));
    }

    protected function setUp()
    {
        parent::setUp();

        $this->loadFixtures([]);

        $this->appClient = $this->makeClient();
        $this->payboxClient = new PayboxClient();
        $this->container = $this->appClient->getContainer();
        $this->donationRepository = $this->getDonationRepository();
    }

    protected function tearDown()
    {
        $this->payboxClient = new PayboxClient();
        $this->donationRepository = null;
        $this->container = null;
        $this->appClient = null;

        parent::tearDown();
    }
}
