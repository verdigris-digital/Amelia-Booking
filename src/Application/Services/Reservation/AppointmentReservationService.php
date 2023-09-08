<?php

namespace AmeliaBooking\Application\Services\Reservation;

use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Bookable\BookableApplicationService;
use AmeliaBooking\Application\Services\Bookable\PackageApplicationService;
use AmeliaBooking\Application\Services\Booking\AppointmentApplicationService;
use AmeliaBooking\Application\Services\Coupon\CouponApplicationService;
use AmeliaBooking\Application\Services\Helper\HelperService;
use AmeliaBooking\Application\Services\TimeSlot\TimeSlotService as ApplicationTimeSlotService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\BookingCancellationException;
use AmeliaBooking\Domain\Common\Exceptions\BookingsLimitReachedException;
use AmeliaBooking\Domain\Common\Exceptions\BookingUnavailableException;
use AmeliaBooking\Domain\Common\Exceptions\CouponExpiredException;
use AmeliaBooking\Domain\Common\Exceptions\CouponInvalidException;
use AmeliaBooking\Domain\Common\Exceptions\CouponUnknownException;
use AmeliaBooking\Domain\Common\Exceptions\CustomerBookedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Common\Exceptions\PackageBookingUnavailableException;
use AmeliaBooking\Domain\Entity\Bookable\AbstractBookable;
use AmeliaBooking\Domain\Entity\Bookable\Service\Extra;
use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBookingExtra;
use AmeliaBooking\Domain\Entity\Booking\Reservation;
use AmeliaBooking\Domain\Entity\Coupon\Coupon;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Location\Location;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Factory\Booking\Appointment\AppointmentFactory;
use AmeliaBooking\Domain\Factory\Booking\Appointment\CustomerBookingFactory;
use AmeliaBooking\Domain\Services\Booking\AppointmentDomainService;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\BooleanValueObject;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\PositiveDuration;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Domain\ValueObjects\String\PaymentStatus;
use AmeliaBooking\Domain\ValueObjects\String\PaymentType;
use AmeliaBooking\Domain\ValueObjects\String\Status;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use AmeliaBooking\Infrastructure\Repository\Location\LocationRepository;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaBooking\Infrastructure\Repository\User\CustomerRepository;
use AmeliaBooking\Infrastructure\WP\Integrations\WooCommerce\WooCommerceService;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;
use DateTime;
use Exception;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class AppointmentReservationService
 *
 * @package AmeliaBooking\Application\Services\Reservation
 */
class AppointmentReservationService extends AbstractReservationService
{
    /**
     * @return string
     */
    public function getType()
    {
        return Entities::APPOINTMENT;
    }

    /**
     * @param array       $appointmentData
     * @param Reservation $reservation
     * @param bool        $save
     *
     * @return void
     *
     * @throws CouponExpiredException
     * @throws CouponInvalidException
     * @throws CouponUnknownException
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws Exception
     * @throws ContainerException
     */
    public function book($appointmentData, $reservation, $save)
    {
        /** @var CouponApplicationService $couponAS */
        $couponAS = $this->container->get('application.coupon.service');

        /** @var Coupon $coupon */
        $coupon = !empty($appointmentData['couponCode']) ? $couponAS->processCoupon(
            $appointmentData['couponCode'],
            array_unique(
                array_merge([$appointmentData['serviceId']], array_column($appointmentData['recurring'], 'serviceId'))
            ),
            Entities::APPOINTMENT,
            $appointmentData['bookings'][0]['customerId'],
            $reservation->hasCouponValidation()->getValue()
        ) : null;

        $allowedCouponLimit = $coupon ? $couponAS->getAllowedCouponLimit(
            $coupon,
            $appointmentData['bookings'][0]['customerId']
        ) : 0;

        if ($allowedCouponLimit > 0 && $coupon->getServiceList()->keyExists($appointmentData['serviceId'])) {
            $appointmentData['bookings'][0]['coupon'] = $coupon->toArray();

            $appointmentData['bookings'][0]['couponId'] = $coupon->getId()->getValue();

            $allowedCouponLimit--;
        }

        $appointmentsDateTimes = !empty($appointmentData['recurring']) ? DateTimeService::getSortedDateTimeStrings(
            array_merge(
                [$appointmentData['bookingStart']],
                array_column($appointmentData['recurring'], 'bookingStart')
            )
        ) : [$appointmentData['bookingStart']];

        $this->bookSingle(
            $reservation,
            $appointmentData,
            DateTimeService::getCustomDateTimeObject($appointmentsDateTimes[0]),
            DateTimeService::getCustomDateTimeObject($appointmentsDateTimes[sizeof($appointmentsDateTimes) - 1]),
            $reservation->hasAvailabilityValidation()->getValue(),
            $save
        );

        $reservation->setApplyDeposit(new BooleanValueObject($appointmentData['bookings'][0]['deposit']));

        /** @var Payment $payment */
        $payment = $save && $reservation->getBooking() && $reservation->getBooking()->getPayments()->length() ?
            $reservation->getBooking()->getPayments()->getItem(0) : null;

        /** @var Service $bookable */
        $bookable = $reservation->getBookable();

        /** @var Collection $recurringReservations */
        $recurringReservations = new Collection();

        if (!empty($appointmentData['recurring'])) {
            foreach ($appointmentData['recurring'] as $index => $recurringData) {
                $recurringAppointmentData = array_merge(
                    $appointmentData,
                    [
                        'providerId'   => $recurringData['providerId'],
                        'locationId'   => $recurringData['locationId'],
                        'bookingStart' => $recurringData['bookingStart'],
                        'parentId'     => $reservation->getReservation()->getId() ?
                            $reservation->getReservation()->getId()->getValue() : null,
                        'recurring'    => [],
                        'package'      => []
                    ]
                );

                if (!empty($recurringAppointmentData['bookings'][0]['utcOffset'])) {
                    $recurringAppointmentData['bookings'][0]['utcOffset'] = $recurringData['utcOffset'];
                }

                if ($allowedCouponLimit > 0 &&
                    $coupon->getServiceList()->keyExists($recurringAppointmentData['serviceId'])
                ) {
                    $recurringAppointmentData['bookings'][0]['coupon'] = $coupon->toArray();

                    $recurringAppointmentData['bookings'][0]['couponId'] = $coupon->getId()->getValue();

                    $allowedCouponLimit--;
                } else {
                    $recurringAppointmentData['bookings'][0]['coupon'] = null;

                    $recurringAppointmentData['bookings'][0]['couponId'] = null;
                }

                if ($index >= $bookable->getRecurringPayment()->getValue()) {
                    $recurringAppointmentData['payment']['gateway'] = PaymentType::ON_SITE;

                    $recurringAppointmentData['bookings'][0]['deposit'] = 0;
                }

                $recurringAppointmentData['payment']['parentId'] = $payment ? $payment->getId()->getValue() : null;

                try {
                    /** @var Reservation $recurringReservation */
                    $recurringReservation = new Reservation();

                    $recurringReservation->setApplyDeposit(
                        new BooleanValueObject($index >= $bookable->getRecurringPayment()->getValue() ? false : $appointmentData['bookings'][0]['deposit'])
                    );

                    $this->bookSingle(
                        $recurringReservation,
                        $recurringAppointmentData,
                        DateTimeService::getCustomDateTimeObject($appointmentsDateTimes[0]),
                        DateTimeService::getCustomDateTimeObject($appointmentsDateTimes[sizeof($appointmentsDateTimes) - 1]),
                        $reservation->hasAvailabilityValidation()->getValue(),
                        $save
                    );
                } catch (Exception $e) {
                    if ($save) {
                        /** @var Reservation $recurringReservation */
                        foreach ($recurringReservations->getItems() as $recurringReservation) {
                            $this->deleteReservation($recurringReservation);
                        }

                        $this->deleteReservation($reservation);
                    }

                    throw $e;
                }

                $recurringReservations->addItem($recurringReservation);
            }
        }

        $reservation->setRecurring($recurringReservations);
        $reservation->setPackageCustomerServices(new Collection());
        $reservation->setPackageReservations(new Collection());
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param Reservation $reservation
     * @param array       $appointmentData
     * @param DateTime    $minimumAppointmentDateTime
     * @param DateTime    $maximumAppointmentDateTime
     * @param bool        $inspectTimeSlot
     * @param bool        $save
     *
     * @return void
     *
     * @throws NotFoundException
     * @throws BookingUnavailableException
     * @throws BookingsLimitReachedException
     * @throws CustomerBookedException
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws Exception
     * @throws ContainerException
     */
    public function bookSingle(
        $reservation,
        $appointmentData,
        $minimumAppointmentDateTime,
        $maximumAppointmentDateTime,
        $inspectTimeSlot,
        $save
    ) {
        /** @var AppointmentApplicationService $appointmentAS */
        $appointmentAS = $this->container->get('application.booking.appointment.service');
        /** @var AppointmentDomainService $appointmentDS */
        $appointmentDS = $this->container->get('domain.booking.appointment.service');
        /** @var AppointmentRepository $appointmentRepo */
        $appointmentRepo = $this->container->get('domain.booking.appointment.repository');
        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->container->get('domain.locations.repository');
        /** @var BookableApplicationService $bookableAS */
        $bookableAS = $this->container->get('application.bookable.service');
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        $appointmentStatusChanged = false;

        /** @var Service $service */
        $service = $bookableAS->getAppointmentService($appointmentData['serviceId'], $appointmentData['providerId']);

        if ($service->getStatus()->getValue() === Status::HIDDEN) {
            throw new BookingUnavailableException(
                FrontendStrings::getCommonStrings()['time_slot_unavailable']
            );
        }

        /** @var Collection $existingAppointments */
        $existingAppointments = $appointmentRepo->getFiltered(
            [
                'dates'         => [$appointmentData['bookingStart'], $appointmentData['bookingStart']],
                'services'      => [$appointmentData['serviceId']],
                'providers'     => [$appointmentData['providerId']],
                'skipServices'  => true,
                'skipProviders' => true,
                'skipCustomers' => true,
            ]
        );

        $bookingStatus = $settingsDS
            ->getEntitySettings($service->getSettings())
            ->getGeneralSettings()
            ->getDefaultAppointmentStatus();

        $appointmentData['bookings'][0]['status'] = $bookingStatus;

        if (!empty($appointmentData['payment']['gateway']) && !empty($appointmentData['payment']['orderStatus'])) {
            $appointmentData['bookings'][0]['status'] = $this->getWcStatus(
                Entities::APPOINTMENT,
                $appointmentData['payment']['orderStatus'],
                'booking',
                false
            ) ?: $bookingStatus;
        }

        /** @var Appointment $existingAppointment */
        $existingAppointment = $existingAppointments->length() ?
            $existingAppointments->getItem($existingAppointments->keys()[0]) : null;

        if ((
                !empty($appointmentData['payment']['gateway']) &&
                $appointmentData['payment']['gateway'] === PaymentType::MOLLIE
            ) && !(
                !empty($appointmentData['bookings'][0]['packageCustomerService']['id']) &&
                $reservation->getLoggedInUser() &&
                $reservation->getLoggedInUser()->getType() === AbstractUser::USER_ROLE_CUSTOMER
            )
        ) {
            $appointmentData['bookings'][0]['status'] = BookingStatus::PENDING;
        }

        if ($existingAppointment) {
            /** @var Appointment $appointment */
            $appointment = AppointmentFactory::create($existingAppointment->toArray());

            if (!empty($appointmentData['locationId'])) {
                $appointment->setLocationId(new Id($appointmentData['locationId']));
            }

            /** @var CustomerBooking $booking */
            $booking = CustomerBookingFactory::create($appointmentData['bookings'][0]);
            $booking->setAppointmentId($appointment->getId());
            $booking->setPrice(
                new Price(
                    $appointmentAS->getBookingPriceForServiceDuration(
                        $service,
                        $booking->getDuration() ? $booking->getDuration()->getValue() : null
                    )
                )
            );

            /** @var CustomerBookingExtra $bookingExtra */
            foreach ($booking->getExtras()->getItems() as $bookingExtra) {
                /** @var Extra $selectedExtra */
                $selectedExtra = $service->getExtras()->getItem($bookingExtra->getExtraId()->getValue());

                $bookingExtra->setPrice($selectedExtra->getPrice());
            }

            $maximumDuration = $appointmentAS->getMaximumBookingDuration($appointment, $service);

            if ($booking->getDuration() &&
                $booking->getDuration()->getValue() > $maximumDuration
            ) {
                $service->setDuration(new PositiveDuration($maximumDuration));
            }
        } else {
            /** @var Appointment $appointment */
            $appointment = $appointmentAS->build($appointmentData, $service);

            /** @var CustomerBooking $booking */
            $booking = $appointment->getBookings()->getItem($appointment->getBookings()->keys()[0]);

            $service->setDuration(
                new PositiveDuration($appointmentAS->getMaximumBookingDuration($appointment, $service))
            );
        }

        $bookableAS->modifyServicePriceByDuration($service, $service->getDuration()->getValue());

        if ($inspectTimeSlot) {
            /** @var ApplicationTimeSlotService $applicationTimeSlotService */
            $applicationTimeSlotService = $this->container->get('application.timeSlot.service');

            // if not new appointment, check if customer has already made booking
            if ($appointment->getId() !== null &&
                !$settingsDS->getSetting('appointments', 'bookMultipleTimes')
            ) {
                foreach ($appointment->getBookings()->keys() as $bookingKey) {
                    /** @var CustomerBooking $customerBooking */
                    $customerBooking = $appointment->getBookings()->getItem($bookingKey);

                    if ($customerBooking->getStatus()->getValue() !== BookingStatus::CANCELED &&
                        $booking->getCustomerId()->getValue() === $customerBooking->getCustomerId()->getValue()
                    ) {
                        throw new CustomerBookedException(
                            FrontendStrings::getCommonStrings()['customer_already_booked_app']
                        );
                    }
                }
            }

            $selectedExtras = [];

            foreach ($booking->getExtras()->keys() as $extraKey) {
                $selectedExtras[] = [
                    'id'       => $booking->getExtras()->getItem($extraKey)->getExtraId()->getValue(),
                    'quantity' => $booking->getExtras()->getItem($extraKey)->getQuantity()->getValue(),
                ];
            }

            if (!$applicationTimeSlotService->isSlotFree(
                $service,
                $appointment->getBookingStart()->getValue(),
                $minimumAppointmentDateTime,
                $maximumAppointmentDateTime,
                $appointment->getProviderId()->getValue(),
                $appointment->getLocationId() ? $appointment->getLocationId()->getValue() : null,
                $selectedExtras,
                null,
                $booking->getPersons()->getValue(),
                empty($appointmentData['packageBookingFromBackend'])
            )) {
                throw new BookingUnavailableException(
                    FrontendStrings::getCommonStrings()['time_slot_unavailable']
                );
            }

            if ($booking->getPackageCustomerService() &&
                $booking->getPackageCustomerService()->getId() &&
                !empty($appointmentData['isCabinetBooking'])
            ) {
                /** @var PackageApplicationService $packageApplicationService */
                $packageApplicationService = $this->container->get('application.bookable.package');

                if (!$packageApplicationService->isBookingAvailableForPurchasedPackage(
                    $booking->getPackageCustomerService()->getId()->getValue(),
                    $booking->getCustomerId()->getValue(),
                    true
                )) {
                    throw new PackageBookingUnavailableException(
                        FrontendStrings::getCommonStrings()['package_booking_unavailable']
                    );
                }
            }

            $isPackageBooking = $booking->getPackageCustomerService() !== null;

            if (!$isPackageBooking &&
                empty($appointmentData['isBackendOrCabinet']) &&
                $this->checkLimitsPerCustomer(
                    $service,
                    $appointmentData['bookings'][0]['customerId'],
                    $appointment->getBookingStart()->getValue()
                )
            ) {
                throw new BookingsLimitReachedException(FrontendStrings::getCommonStrings()['bookings_limit_reached']);
            }
        }

        if ($save) {
            if ($existingAppointment) {
                $appointment->getBookings()->addItem($booking);
                $bookingsCount = $appointmentDS->getBookingsStatusesCount($appointment);

                $appointmentStatus = $appointmentDS->getAppointmentStatusWhenEditAppointment($service, $bookingsCount);
                $appointment->setStatus(new BookingStatus($appointmentStatus));
                $appointmentStatusChanged = $existingAppointment->getStatus()->getValue() !== BookingStatus::CANCELED &&
                    $appointmentAS->isAppointmentStatusChanged(
                        $appointment,
                        $existingAppointment
                    );

                $appointmentAS->calculateAndSetAppointmentEnd($appointment, $service);

                $appointmentAS->update(
                    $existingAppointment,
                    $appointment,
                    new Collection(),
                    $service,
                    $appointmentData['payment']
                );
            } else {
                $appointmentAS->add(
                    $appointment,
                    $service,
                    !empty($appointmentData['payment']) ? $appointmentData['payment'] : null,
                    !empty($appointmentData['payment']['isBackendBooking'])
                );
            }
        }

        if ($appointment->getLocationId()) {
            /** @var Location $location */
            $location = $locationRepository->getById($appointment->getLocationId()->getValue());

            $appointment->setLocation($location);
        }

        $reservation->setCustomer($booking->getCustomer());
        $reservation->setBookable($service);
        $reservation->setBooking($booking);
        $reservation->setReservation($appointment);
        $reservation->setIsStatusChanged(new BooleanValueObject($appointmentStatusChanged));
    }

    /**
     * @param CustomerBooking $booking
     * @param string          $requestedStatus
     *
     * @return array
     *
     * @throws \Slim\Exception\ContainerException
     * @throws \InvalidArgumentException
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws NotFoundException
     * @throws BookingCancellationException
     */
    public function updateStatus($booking, $requestedStatus)
    {
        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');
        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container->get('domain.booking.appointment.repository');
        /** @var AppointmentDomainService $appointmentDS */
        $appointmentDS = $this->container->get('domain.booking.appointment.service');
        /** @var AppointmentApplicationService $appointmentAS */
        $appointmentAS = $this->container->get('application.booking.appointment.service');
        /** @var BookableApplicationService $bookableAS */
        $bookableAS = $this->container->get('application.bookable.service');
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        /** @var Appointment $appointment */
        $appointment = $appointmentRepository->getById($booking->getAppointmentId()->getValue());

        /** @var Service $service */
        $service = $bookableAS->getAppointmentService(
            $appointment->getServiceId()->getValue(),
            $appointment->getProviderId()->getValue()
        );

        $requestedStatus = $requestedStatus === null ? $settingsDS
            ->getEntitySettings($service->getSettings())
            ->getGeneralSettings()
            ->getDefaultAppointmentStatus() : $requestedStatus;

        if ($requestedStatus === BookingStatus::CANCELED) {
            $minimumCancelTime = $settingsDS
                ->getEntitySettings($service->getSettings())
                ->getGeneralSettings()
                ->getMinimumTimeRequirementPriorToCanceling();

            $this->inspectMinimumCancellationTime($appointment->getBookingStart()->getValue(), $minimumCancelTime);
        }

        $appointment->getBookings()->getItem($booking->getId()->getValue())->setStatus(
            new BookingStatus($requestedStatus)
        );

        $oldBookingStatus = $booking->getStatus()->getValue();

        $booking->setStatus(new BookingStatus($requestedStatus));

        $bookingsCount = $appointmentDS->getBookingsStatusesCount($appointment);

        $appointmentStatus = $appointmentDS->getAppointmentStatusWhenChangingBookingStatus(
            $service,
            $bookingsCount,
            $requestedStatus
        );

        $appointmentAS->calculateAndSetAppointmentEnd($appointment, $service);

        $appointmentRepository->beginTransaction();

        try {
            $bookingRepository->updateStatusById($booking->getId()->getValue(), $requestedStatus);
            $appointmentRepository->updateStatusById($booking->getAppointmentId()->getValue(), $appointmentStatus);
            $appointmentRepository->updateFieldById(
                $appointment->getId()->getValue(),
                DateTimeService::getCustomDateTimeInUtc(
                    $appointment->getBookingEnd()->getValue()->format('Y-m-d H:i:s')
                ),
                'bookingEnd'
            );
        } catch (QueryExecutionException $e) {
            $appointmentRepository->rollback();
            throw $e;
        }

        $appStatusChanged = false;

        if ($appointment->getStatus()->getValue() !== $appointmentStatus) {
            $appointment->setStatus(new BookingStatus($appointmentStatus));

            $appStatusChanged = true;

            /** @var CustomerBooking $customerBooking */
            foreach ($appointment->getBookings()->getItems() as $customerBooking) {
                if (($customerBooking->getStatus()->getValue() === BookingStatus::APPROVED &&
                    $appointment->getStatus()->getValue() === BookingStatus::PENDING) ||
                    $booking->getId()->getValue() === $customerBooking->getId()->getValue()
                ) {
                    $customerBooking->setChangedStatus(new BooleanValueObject(true));
                }
            }
        }

        if ((
                ($oldBookingStatus === BookingStatus::CANCELED || $oldBookingStatus === BookingStatus::REJECTED) &&
                ($requestedStatus === BookingStatus::PENDING || $requestedStatus === BookingStatus::APPROVED)
            ) || (
                ($requestedStatus === BookingStatus::CANCELED || $requestedStatus === BookingStatus::REJECTED) &&
                ($oldBookingStatus === BookingStatus::PENDING || $oldBookingStatus === BookingStatus::APPROVED)
            )
        ) {
            $booking->setChangedStatus(new BooleanValueObject(true));
        }

        $appointmentRepository->commit();

        return [
            Entities::APPOINTMENT      => $appointment->toArray(),
            'appointmentStatusChanged' => $appStatusChanged,
            Entities::BOOKING          => $booking->toArray()
        ];
    }

    /**
     * @param Appointment      $reservation
     * @param CustomerBooking  $booking
     * @param Service          $bookable
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function getBookingPeriods($reservation, $booking, $bookable)
    {
        $duration = ($booking->getDuration() && $booking->getDuration()->getValue() !== $bookable->getDuration()->getValue())
            ? $booking->getDuration()->getValue() : $bookable->getDuration()->getValue();

        /** @var CustomerBookingExtra $bookingExtra */
        foreach ($booking->getExtras()->getItems() as $bookingExtra) {
            /** @var Extra $extra */
            $extra = $bookable->getExtras()->getItem($bookingExtra->getExtraId()->getValue());

            $duration += ($extra->getDuration() ? $bookingExtra->getQuantity()->getValue() * $extra->getDuration()->getValue() : 0);
        }

        return [
            [
                'start' => DateTimeService::getCustomDateTimeInUtc(
                    $reservation->getBookingStart()->getValue()->format('Y-m-d H:i:s')
                ),
                'end'   => DateTimeService::getCustomDateTimeInUtc(
                    DateTimeService::getCustomDateTimeObject(
                        $reservation->getBookingStart()->getValue()->format('Y-m-d H:i:s')
                    )->modify("+{$duration} seconds")->format('Y-m-d H:i:s')
                )
            ]
        ];
    }

    /**
     * @param array $data
     *
     * @return AbstractBookable
     *
     * @throws InvalidArgumentException
     * @throws ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws NotFoundException
     */
    public function getBookableEntity($data)
    {
        /** @var BookableApplicationService $bookableAS */
        $bookableAS = $this->container->get('application.bookable.service');

        return $bookableAS->getAppointmentService($data['serviceId'], $data['providerId']);
    }

    /**
     * @param Service $bookable
     *
     * @return boolean
     */
    public function isAggregatedPrice($bookable)
    {
        return $bookable->getAggregatedPrice()->getValue();
    }

    /**
     * @param BooleanValueObject $bookableAggregatedPrice
     * @param BooleanValueObject $extraAggregatedPrice
     *
     * @return boolean
     */
    public function isExtraAggregatedPrice($extraAggregatedPrice, $bookableAggregatedPrice)
    {
        return $extraAggregatedPrice === null ?
            $bookableAggregatedPrice->getValue() : $extraAggregatedPrice->getValue();
    }

    /**
     * @param Reservation $reservation
     * @param string      $paymentGateway
     * @param array       $requestData
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    public function getWooCommerceData($reservation, $paymentGateway, $requestData)
    {
        /** @var Appointment $appointment */
        $appointment = $reservation->getReservation();

        /** @var Service $service */
        $service = $reservation->getBookable();

        /** @var AbstractUser $customer */
        $customer = $reservation->getCustomer();

        /** @var CustomerBooking $booking */
        $booking = $reservation->getBooking();

        $recurringAppointmentsData = [];

        if ($reservation->getRecurring()) {
            /** @var Reservation $recurringReservation */
            foreach ($reservation->getRecurring()->getItems() as $key => $recurringReservation) {
                $recurringAppointmentData = [
                    'providerId'         => $recurringReservation->getReservation()->getProviderId()->getValue(),
                    'locationId'         => $recurringReservation->getReservation()->getLocationId() ?
                        $recurringReservation->getReservation()->getLocationId()->getValue() : null,
                    'bookingStart'       =>
                        $recurringReservation->getReservation()->getBookingStart()->getValue()->format('Y-m-d H:i:s'),
                    'bookingEnd'         =>
                        $recurringReservation->getReservation()->getBookingEnd()->getValue()->format('Y-m-d H:i:s'),
                    'notifyParticipants' => $recurringReservation->getReservation()->isNotifyParticipants(),
                    'status'             => $recurringReservation->getReservation()->getStatus()->getValue(),
                    'utcOffset'          => $recurringReservation->getBooking()->getUtcOffset() ?
                        $recurringReservation->getBooking()->getUtcOffset()->getValue() : null,
                    'deposit'            => $recurringReservation->getApplyDeposit()->getValue(),
                    'useCoupon'          => $recurringReservation->getBooking()->getCouponId() ? true : false,
                ];

                $recurringAppointmentData['couponId'] = !$recurringReservation->getBooking()->getCoupon() ? null :
                    $recurringReservation->getBooking()->getCoupon()->getId()->getValue();

                $recurringAppointmentData['couponCode'] = !$recurringReservation->getBooking()->getCoupon() ? null :
                    $recurringReservation->getBooking()->getCoupon()->getCode()->getValue();

                $recurringAppointmentData['useCoupon'] = $recurringReservation->getBooking()->getCoupon() !== null;

                $recurringAppointmentsData[] = $recurringAppointmentData;
            }
        }

        $info = [
            'type'               => Entities::APPOINTMENT,
            'serviceId'          => $service->getId()->getValue(),
            'providerId'         => $appointment->getProviderId()->getValue(),
            'locationId'         => $appointment->getLocationId() ? $appointment->getLocationId()->getValue() : null,
            'name'               => $service->getName()->getValue(),
            'couponId'           => $booking->getCoupon() ? $booking->getCoupon()->getId()->getValue() : '',
            'couponCode'         => $booking->getCoupon() ? $booking->getCoupon()->getCode()->getValue() : '',
            'bookingStart'       => $appointment->getBookingStart()->getValue()->format('Y-m-d H:i'),
            'bookingEnd'         => $appointment->getBookingEnd()->getValue()->format('Y-m-d H:i'),
            'status'             => $appointment->getStatus()->getValue(),
            'dateTimeValues'     => [
                [
                    'start' => $appointment->getBookingStart()->getValue()->format('Y-m-d H:i'),
                    'end'   => $appointment->getBookingEnd()->getValue()->format('Y-m-d H:i'),
                ]
            ],
            'notifyParticipants' => $appointment->isNotifyParticipants(),
            'bookings'           => [
                [
                    'customerId'   => $customer->getId() ? $customer->getId()->getValue() : null,
                    'customer'     => [
                        'email'           => $customer->getEmail()->getValue(),
                        'externalId'      => $customer->getExternalId() ? $customer->getExternalId()->getValue() : null,
                        'firstName'       => $customer->getFirstName()->getValue(),
                        'id'              => $customer->getId() ? $customer->getId()->getValue() : null,
                        'lastName'        => $customer->getLastName()->getValue(),
                        'phone'           => $customer->getPhone()->getValue(),
                        'countryPhoneIso' => $customer->getCountryPhoneIso() ?
                            $customer->getCountryPhoneIso()->getValue() : null,
                    ],
                    'info'         => $booking->getInfo()->getValue(),
                    'persons'      => $booking->getPersons()->getValue(),
                    'extras'       => [],
                    'status'       => $booking->getStatus()->getValue(),
                    'utcOffset'    => $booking->getUtcOffset() ? $booking->getUtcOffset()->getValue() : null,
                    'customFields' => $booking->getCustomFields() ?
                        json_decode($booking->getCustomFields()->getValue(), true) : null,
                    'deposit'      => $reservation->getApplyDeposit()->getValue(),
                    'duration'     => $booking->getDuration() ? $booking->getDuration()->getValue() : null,
                ]
            ],
            'payment'            => [
                'gateway' => $paymentGateway
            ],
            'recurring'          => $recurringAppointmentsData,
            'package'            => [],
            'locale'             => $reservation->getLocale()->getValue(),
            'timeZone'           => $reservation->getTimeZone()->getValue(),
        ];

        foreach ($booking->getExtras()->keys() as $extraKey) {
            /** @var CustomerBookingExtra $bookingExtra */
            $bookingExtra = $booking->getExtras()->getItem($extraKey);

            $info['bookings'][0]['extras'][] = [
                'extraId'  => $bookingExtra->getExtraId()->getValue(),
                'quantity' => $bookingExtra->getQuantity()->getValue()
            ];
        }

        return $info;
    }



    /**
     * @param array $reservation
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    public function getWooCommerceDataFromArray($reservation, $index)
    {
        /** @var array $appointment */
        $appointment = $reservation['appointment'];

        /** @var array $service */
        $service = $reservation['bookable'];

        /** @var array $customer */
        $customer = $reservation['customer'];

        /** @var array $booking */
        $booking = $reservation['booking'];

        $customerInfo = !empty($booking['info']) ? json_decode($booking['info'], true) : null;

        $recurringAppointmentsData = [];

        if (!empty($reservation['recurring'])) {
            /** @var Reservation $recurringReservation */
            foreach ($reservation['recurring'] as $key => $recurringReservation) {
                $recurringAppointmentData = [
                    'providerId'         => $recurringReservation['providerId'],
                    'locationId'         => $recurringReservation['locationId'],
                    'bookingStart'       => $recurringReservation['bookingStart'],
                    'bookingEnd'         => $recurringReservation['bookingEnd'],
                    'notifyParticipants' => $recurringReservation['notifyParticipants'],
                    'status'             => $recurringReservation['status'],
                    'utcOffset'          => $recurringReservation['bookings'][$index]['utcOffset'],
                ];

                $recurringAppointmentData['couponId'] = $recurringReservation['bookings'][$index]['couponId'];

                $recurringAppointmentData['couponCode'] = !$recurringReservation['bookings'][$index]['coupon'] ? null : $recurringReservation['bookings'][0]['coupon']['code'];

                $recurringAppointmentData['useCoupon'] = !!$recurringReservation['bookings'][$index]['couponId'];

                $recurringAppointmentsData[] = $recurringAppointmentData;
            }
        }

        $info = [
            'type'               => Entities::APPOINTMENT,
            'serviceId'          => $service['id'],
            'providerId'         => $appointment['providerId'],
            'locationId'         => $appointment['locationId'],
            'name'               => $service['name'],
            'couponId'           => $booking['couponId'],
            'couponCode'         => !empty($booking['coupon']) ? $booking['coupon']['code'] : null,
            'bookingStart'       => $appointment['bookingStart'],
            'bookingEnd'         => $appointment['bookingEnd'],
            'status'             => $appointment['status'],
            'dateTimeValues'     => [
                [
                    'start' => $appointment['bookingStart'],
                    'end'   => $appointment['bookingEnd'],
                ]
            ],
            'notifyParticipants' => $appointment['notifyParticipants'],
            'bookings'           => [
                [
                    'customerId'   => $customer['id'],
                    'customer'     => [
                        'email'           => $customer['email'],
                        'externalId'      => $customer['externalId'],
                        'firstName'       => $customer['firstName'],
                        'id'              => $customer['id'],
                        'lastName'        => $customer['lastName'],
                        'phone'           => $customer['phone'],
                        'countryPhoneIso' => $customer['countryPhoneIso'],
                    ],
                    'info'         => $booking['info'],
                    'persons'      => $booking['persons'],
                    'extras'       => [],
                    'status'       => $booking['status'],
                    'utcOffset'    => $booking['utcOffset'],
                    'customFields' => !empty($booking['customFields']) ?
                        json_decode($booking['customFields'], true) : null,
                    'deposit'      => $booking['payments'][0]['status'] === PaymentStatus::PARTIALLY_PAID,
                    'duration'     => $booking['duration'],
                ]
            ],
            'payment'            => [
                'gateway' => $booking['payments'][0]['gateway']
            ],
            'recurring'          => $recurringAppointmentsData,
            'package'            => [],
            'locale'             => $customerInfo ? $customerInfo['locale'] : null,
            'timeZone'           => $customerInfo ? $customerInfo['timeZone'] : null,
        ];

        foreach ($booking['extras'] as $extra) {
            $info['bookings'][0]['extras'][] = [
                'extraId'  => $extra['extraId'],
                'quantity' => $extra['quantity']
            ];
        }

        return $info;
    }



    /**
     * @param CustomerBooking $booking
     * @param Appointment     $appointment
     *
     * @return array
     *
     * @throws ContainerException
     */
    public function updateWooCommerceOrder($booking, $appointment)
    {
        /** @var Payment $payment */
        foreach ($booking->getPayments()->getItems() as $payment) {
            if ($payment->getWcOrderId() && $payment->getWcOrderId()->getValue()) {
                $appointmentArrayModified = $appointment->toArray();

                $appointmentArrayModified['bookings'] = [$booking->toArray()];

                foreach ($appointmentArrayModified['bookings'] as &$booking2) {
                    if (!empty($booking2['customFields'])) {
                        $customFields = json_decode($booking2['customFields'], true);

                        $booking2['customFields'] = $customFields;
                    }
                }

                $appointmentArrayModified['dateTimeValues'] = [
                    [
                        'start' => $appointment->getBookingStart()->getValue()->format('Y-m-d H:i'),
                        'end'   => $appointment->getBookingEnd()->getValue()->format('Y-m-d H:i'),
                    ]
                ];

                WooCommerceService::updateItemMetaData(
                    $payment->getWcOrderId()->getValue(),
                    $appointmentArrayModified
                );

                foreach ($appointmentArrayModified['bookings'] as &$bookingArray) {
                    if (!empty($bookingArray['customFields'])) {
                        $bookingArray['customFields'] = json_encode($bookingArray['customFields']);
                    }
                }
            }
        }
    }

    /**
     * @param int $id
     *
     * @return Appointment
     *
     * @throws ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     */
    public function getReservationByBookingId($id)
    {
        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

        /** @var Appointment $appointment */
        return $appointmentRepository->getByBookingId($id);
    }

    /**
     * @param Reservation  $reservation
     *
     * @return float
     *
     * @throws InvalidArgumentException
     */
    public function getReservationPaymentAmount($reservation)
    {
        /** @var Service $bookable */
        $bookable = $reservation->getBookable();

        $paymentAmount = $this->getPaymentAmount($reservation->getBooking(), $bookable);

        if ($reservation->getApplyDeposit()->getValue()) {
            $paymentAmount = $this->calculateDepositAmount(
                $paymentAmount,
                $bookable,
                $reservation->getBooking()->getPersons()->getValue()
            );
        }

        /** @var Reservation $recurringReservation */
        foreach ($reservation->getRecurring()->getItems() as $index => $recurringReservation) {
            /** @var Service $recurringBookable */
            $recurringBookable = $recurringReservation->getBookable();

            if ($index < $recurringBookable->getRecurringPayment()->getValue()) {
                $recurringPaymentAmount = $this->getPaymentAmount(
                    $recurringReservation->getBooking(),
                    $recurringBookable
                );

                if ($recurringReservation->getApplyDeposit()->getValue()) {
                    $recurringPaymentAmount = $this->calculateDepositAmount(
                        $recurringPaymentAmount,
                        $recurringBookable,
                        $recurringReservation->getBooking()->getPersons()->getValue()
                    );
                }

                $paymentAmount += $recurringPaymentAmount;
            }
        }

        return $paymentAmount;
    }

    /**
     * @param Payment $payment
     * @param boolean $fromLink
     *
     * @return CommandResult
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function getReservationByPayment($payment, $fromLink = false)
    {
        $result = new CommandResult();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

        /** @var CustomerRepository $customerRepository */
        $customerRepository = $this->container->get('domain.users.customers.repository');

        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->container->get('domain.locations.repository');

        /** @var BookableApplicationService $bookableAS */
        $bookableAS = $this->container->get('application.bookable.service');

        $recurringData = [];

        /** @var Appointment $appointment */
        $appointment = $appointmentRepository->getByPaymentId($payment->getId()->getValue());

        if ($appointment->getLocationId()) {
            /** @var Location $location */
            $location = $locationRepository->getById($appointment->getLocationId()->getValue());

            $appointment->setLocation($location);
        }

        /** @var CustomerBooking $booking */
        $booking = $appointment->getBookings()->getItem($payment->getCustomerBookingId()->getValue());

        $booking->setChangedStatus(new BooleanValueObject(true));

        $this->setToken($booking);

        /** @var AbstractUser $customer */
        $customer = $customerRepository->getById($booking->getCustomerId()->getValue());

        /** @var Collection $nextPayments */
        $nextPayments = $paymentRepository->getByEntityId($payment->getId()->getValue(), 'parentId');

        /** @var Payment $nextPayment */
        foreach ($nextPayments->getItems() as $nextPayment) {
            /** @var Appointment $nextAppointment */
            $nextAppointment = $appointmentRepository->getByPaymentId($nextPayment->getId()->getValue());

            if ($nextAppointment->getLocationId()) {
                /** @var Location $location */
                $location = $locationRepository->getById($nextAppointment->getLocationId()->getValue());

                $nextAppointment->setLocation($location);
            }

            /** @var CustomerBooking $nextBooking */
            $nextBooking = $nextAppointment->getBookings()->getItem(
                $nextPayment->getCustomerBookingId()->getValue()
            );

            /** @var Service $nextService */
            $nextService = $bookableAS->getAppointmentService(
                $nextAppointment->getServiceId()->getValue(),
                $nextAppointment->getProviderId()->getValue()
            );

            $recurringData[] = [
                'type'                     => Entities::APPOINTMENT,
                Entities::APPOINTMENT      => $nextAppointment->toArray(),
                Entities::BOOKING          => $nextBooking->toArray(),
                'appointmentStatusChanged' => true,
                'utcTime'                  => $this->getBookingPeriods(
                    $nextAppointment,
                    $nextBooking,
                    $nextService
                ),
                'isRetry'                  => !$fromLink,
                'fromLink'                 => $fromLink
            ];
        }

        /** @var Service $service */
        $service = $bookableAS->getAppointmentService(
            $appointment->getServiceId()->getValue(),
            $appointment->getProviderId()->getValue()
        );

        $customerCabinetUrl = '';

        if ($customer &&
            $customer->getEmail() &&
            $customer->getEmail()->getValue() &&
            $booking->getInfo() &&
            $booking->getInfo()->getValue()
        ) {
            $infoJson = json_decode($booking->getInfo()->getValue(), true);

            /** @var HelperService $helperService */
            $helperService = $this->container->get('application.helper.service');

            $customerCabinetUrl = $helperService->getCustomerCabinetUrl(
                $customer->getEmail()->getValue(),
                'email',
                $appointment->getBookingStart()->getValue()->format('Y-m-d'),
                $appointment->getBookingEnd()->getValue()->format('Y-m-d'),
                $infoJson['locale']
            );
        }

        $result->setData(
            [
                'type'                     => Entities::APPOINTMENT,
                Entities::APPOINTMENT      => $appointment->toArray(),
                Entities::BOOKING          => $booking->toArray(),
                'customer'                 => $customer->toArray(),
                'packageId'                => 0,
                'recurring'                => $recurringData,
                'appointmentStatusChanged' => true,
                'bookable'                 => $service->toArray(),
                'utcTime'                  => $this->getBookingPeriods(
                    $appointment,
                    $booking,
                    $service
                ),
                'isRetry'                  => !$fromLink,
                'paymentId'                => $payment->getId()->getValue(),
                'packageCustomerId'        => null,
                'payment'                  => [
                    'id'           => $payment->getId()->getValue(),
                    'amount'       => $payment->getAmount()->getValue(),
                    'status'       => $payment->getStatus()->getValue(),
                    'gateway'      => $payment->getGateway()->getName()->getValue(),
                    'gatewayTitle' => $payment->getGatewayTitle() ? $payment->getGatewayTitle()->getValue() : '',
                ],
                'customerCabinetUrl'       => $customerCabinetUrl,
                'fromLink'                 => $fromLink
            ]
        );

        return $result;
    }

    /**
     * @param int $bookingId
     *
     * @return CommandResult
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function getBookingResultByBookingId($bookingId)
    {
        $result = new CommandResult();

        /** @var AppointmentRepository $appointmentRepository */
        $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

        /** @var CustomerRepository $customerRepository */
        $customerRepository = $this->container->get('domain.users.customers.repository');

        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->container->get('domain.locations.repository');

        /** @var BookableApplicationService $bookableAS */
        $bookableAS = $this->container->get('application.bookable.service');

        $recurringData = [];

        /** @var Appointment $appointment */
        $appointment = $appointmentRepository->getByBookingId($bookingId);

        if ($appointment->getLocationId()) {
            /** @var Location $location */
            $location = $locationRepository->getById($appointment->getLocationId()->getValue());

            $appointment->setLocation($location);
        }

        /** @var CustomerBooking $booking */
        $booking = $appointment->getBookings()->getItem($bookingId);

        $booking->setChangedStatus(new BooleanValueObject(true));

        $this->setToken($booking);

        /** @var AbstractUser $customer */
        $customer = $customerRepository->getById($booking->getCustomerId()->getValue());

        /** @var Service $service */
        $service = $bookableAS->getAppointmentService(
            $appointment->getServiceId()->getValue(),
            $appointment->getProviderId()->getValue()
        );

        $customerCabinetUrl = '';

        if ($customer &&
            $customer->getEmail() &&
            $customer->getEmail()->getValue() &&
            $booking->getInfo() &&
            $booking->getInfo()->getValue()
        ) {
            $infoJson = json_decode($booking->getInfo()->getValue(), true);

            /** @var HelperService $helperService */
            $helperService = $this->container->get('application.helper.service');

            $customerCabinetUrl = $helperService->getCustomerCabinetUrl(
                $customer->getEmail()->getValue(),
                'email',
                $appointment->getBookingStart()->getValue()->format('Y-m-d'),
                $appointment->getBookingEnd()->getValue()->format('Y-m-d'),
                $infoJson['locale']
            );
        }

        $result->setData(
            [
                'type'                     => Entities::APPOINTMENT,
                Entities::APPOINTMENT      => $appointment->toArray(),
                Entities::BOOKING          => $booking->toArray(),
                'customer'                 => $customer->toArray(),
                'packageId'                => 0,
                'recurring'                => $recurringData,
                'appointmentStatusChanged' => true,
                'bookable'                 => $service->toArray(),
                'utcTime'                  => $this->getBookingPeriods(
                    $appointment,
                    $booking,
                    $service
                ),
                'isRetry'                  => true,
                'paymentId'                => null,
                'packageCustomerId'        => null,
                'payment'                  => null,
                'customerCabinetUrl'       => $customerCabinetUrl,
            ]
        );

        return $result;
    }

    /**
     * @param Service $service
     * @param int $customerId
     * @param DateTime $appointmentStart
     *
     * @return bool
     * @throws Exception
     */
    public function checkLimitsPerCustomer($service, $customerId, $appointmentStart)
    {
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        $limitPerCustomerGlobal = $settingsDS->getSetting('roles', 'limitPerCustomerService');
        if (!empty($limitPerCustomerGlobal) || !empty($service->getLimitPerCustomer())) {
            $limitService  = !empty($service->getLimitPerCustomer()) ? json_decode($service->getLimitPerCustomer()->getValue(), true) : null;
            $optionEnabled = empty($limitService) ? $limitPerCustomerGlobal['enabled'] : $limitService['enabled'];
            if ($optionEnabled) {
                /** @var AppointmentRepository $appointmentRepository */
                $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

                $serviceSpecific  = !empty($limitService['timeFrame']) || !empty($limitService['period']) || !empty($limitService['from']) || !empty($limitService['numberOfApp']);
                $limitPerCustomer = !empty($limitService) ? [
                    'numberOfApp' => !empty($limitService['numberOfApp']) ? $limitService['numberOfApp'] : $limitPerCustomerGlobal['numberOfApp'],
                    'timeFrame'   => !empty($limitService['timeFrame']) ? $limitService['timeFrame'] : $limitPerCustomerGlobal['timeFrame'],
                    'period'      => !empty($limitService['period']) ? $limitService['period'] : $limitPerCustomerGlobal['period'],
                    'from'        => !empty($limitService['from']) ? $limitService['from'] : $limitPerCustomerGlobal['from']
                ] : $limitPerCustomerGlobal;

                $count = $appointmentRepository->getRelevantAppointmentsCount(
                    $service,
                    $customerId,
                    $appointmentStart,
                    $limitPerCustomer,
                    $serviceSpecific
                );

                if ($count >= $limitPerCustomer['numberOfApp']) {
                    return true;
                }
            }
        }
        return false;
    }
}
