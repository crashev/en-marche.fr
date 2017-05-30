<?php

namespace AppBundle\Donation;

class DonationSubscription
{
    /**
     * @DonationFrequency()
     */
    private $duration;

    public function __construct(string $duration)
    {
        $this->duration = $duration;
    }

    public function getDuration(): string
    {
        return $this->duration;
    }

    public function setDuration(string $duration): void
    {
        $this->duration = $duration;
    }
}
