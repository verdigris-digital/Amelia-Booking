<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Domain\Entity\Bookable\Service;

use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\ValueObjects\DateTime\DateTimeValue;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\WholeNumber;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Domain\Entity\Coupon\Coupon;

/**
 * Class PackageCustomer
 *
 * @package AmeliaBooking\Domain\Entity\Bookable\Service
 */
class PackageCustomer
{
    /** @var Id */
    private $id;

    /** @var Id */
    private $packageId;

    /** @var Id */
    private $customerId;

    /** @var Price */
    private $price;

    /** @var DateTimeValue */
    private $end;

    /** @var DateTimeValue */
    private $start;

    /** @var DateTimeValue */
    private $purchased;

    /** @var Collection */
    private $payments;

    /** @var BookingStatus */
    protected $status;

    /** @var WholeNumber */
    private $bookingsCount;

    /** @var Id */
    private $couponId;

    /** @var Coupon */
    protected $coupon;

    /**
     * @return Id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param Id $id
     */
    public function setId(Id $id)
    {
        $this->id = $id;
    }

    /**
     * @return Id
     */
    public function getPackageId()
    {
        return $this->packageId;
    }

    /**
     * @param Id $packageId
     */
    public function setPackageId(Id $packageId)
    {
        $this->packageId = $packageId;
    }

    /**
     * @return Id
     */
    public function getCustomerId()
    {
        return $this->customerId;
    }

    /**
     * @param Id $customerId
     */
    public function setCustomerId(Id $customerId)
    {
        $this->customerId = $customerId;
    }

    /**
     * @return Price
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * @param Price $price
     */
    public function setPrice(Price $price)
    {
        $this->price = $price;
    }

    /**
     * @return Collection
     */
    public function getPayments()
    {
        return $this->payments;
    }

    /**
     * @param Collection $payments
     */
    public function setPayments(Collection $payments)
    {
        $this->payments = $payments;
    }

    /**
     * @return DateTimeValue
     */
    public function getEnd()
    {
        return $this->end;
    }

    /**
     * @param DateTimeValue $end
     */
    public function setEnd(DateTimeValue $end)
    {
        $this->end = $end;
    }

    /**
     * @return DateTimeValue
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * @param DateTimeValue $start
     */
    public function setStart(DateTimeValue $start)
    {
        $this->start = $start;
    }

    /**
     * @return DateTimeValue
     */
    public function getPurchased()
    {
        return $this->purchased;
    }

    /**
     * @param DateTimeValue $purchased
     */
    public function setPurchased(DateTimeValue $purchased)
    {
        $this->purchased = $purchased;
    }

    /**
     * @return BookingStatus
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param BookingStatus $status
     */
    public function setStatus(BookingStatus $status)
    {
        $this->status = $status;
    }

    /**
     * @return WholeNumber
     */
    public function getBookingsCount()
    {
        return $this->bookingsCount;
    }

    /**
     * @param WholeNumber $bookingsCount
     */
    public function setBookingsCount(WholeNumber $bookingsCount)
    {
        $this->bookingsCount = $bookingsCount;
    }

    /**
     * @return Id
     */
    public function getCouponId()
    {
        return $this->couponId;
    }

    /**
     * @param Id $couponId
     */
    public function setCouponId(Id $couponId)
    {
        $this->couponId = $couponId;
    }

    /**
     * @return Coupon
     */
    public function getCoupon()
    {
        return $this->coupon;
    }

    /**
     * @param Coupon $coupon
     */
    public function setCoupon(Coupon $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $dateTimeFormat = 'Y-m-d H:i:s';

        return [
            'id'            => $this->getId() ? $this->getId()->getValue() : null,
            'packageId'     => $this->getPackageId() ? $this->getPackageId()->getValue() : null,
            'customerId'    => $this->getCustomerId() ? $this->getCustomerId()->getValue() : null,
            'price'         => $this->getPrice() ? $this->getPrice()->getValue() : null,
            'payments'      => $this->getPayments() ? $this->getPayments()->toArray() : null,
            'start'         => $this->getStart() ? $this->getStart()->getValue()->format($dateTimeFormat) : null,
            'end'           => $this->getEnd() ? $this->getEnd()->getValue()->format($dateTimeFormat) : null,
            'purchased'     => $this->getPurchased() ?
                $this->getPurchased()->getValue()->format($dateTimeFormat) : null,
            'status'        => $this->getStatus() ? $this->getStatus()->getValue() : null,
            'bookingsCount' => $this->getBookingsCount() ? $this->getBookingsCount()->getValue() : null,
            'couponId'      => $this->getCouponId() ? $this->getCouponId()->getValue() : null,
            'coupon'        => $this->getCoupon() ? $this->getCoupon()->toArray() : null,
        ];
    }
}
