<?php

namespace AmeliaBooking\Application\Services\Payment;

use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Bookable\PackageApplicationService;
use AmeliaBooking\Application\Services\Placeholder\PlaceholderService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\AbstractBookable;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Booking\Event\Event;
use AmeliaBooking\Domain\Entity\Booking\Reservation;
use AmeliaBooking\Domain\Entity\Cache\Cache;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Entity\User\Provider;
use AmeliaBooking\Domain\Factory\Payment\PaymentFactory;
use AmeliaBooking\Domain\Factory\User\UserFactory;
use AmeliaBooking\Domain\Services\Payment\PaymentServiceInterface;
use AmeliaBooking\Domain\Services\Reservation\ReservationServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\BooleanValueObject;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Domain\ValueObjects\String\BookingType;
use AmeliaBooking\Domain\ValueObjects\String\Name;
use AmeliaBooking\Domain\ValueObjects\String\PaymentStatus;
use AmeliaBooking\Domain\ValueObjects\String\PaymentType;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\CustomerBookingEventTicketRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventRepository;
use AmeliaBooking\Infrastructure\Repository\Cache\CacheRepository;
use AmeliaBooking\Infrastructure\Repository\Coupon\CouponRepository;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaBooking\Infrastructure\Services\Payment\CurrencyService;
use AmeliaBooking\Infrastructure\Services\Payment\PayPalService;
use AmeliaBooking\Infrastructure\Services\Payment\RazorpayService;
use AmeliaBooking\Infrastructure\Services\Payment\StripeService;
use AmeliaBooking\Infrastructure\WP\HelperService\HelperService;
use AmeliaBooking\Infrastructure\WP\Integrations\WooCommerce\WooCommerceService;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;
use Exception;
use Razorpay\Api\Errors\SignatureVerificationError;
use Slim\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class PaymentApplicationService
 *
 * @package AmeliaBooking\Application\Services\Payment
 */
class PaymentApplicationService
{

    private $container;

    /**
     * PaymentApplicationService constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param array $params
     * @param int   $itemsPerPage
     *
     * @return array
     *
     * @throws ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     */
    public function getPaymentsData($params, $itemsPerPage)
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        /** @var EventRepository $eventRepository */
        $eventRepository = $this->container->get('domain.booking.event.repository');

        /** @var PackageApplicationService $packageApplicationService */
        $packageApplicationService = $this->container->get('application.bookable.package');

        $paymentsData = $paymentRepository->getFiltered($params, $itemsPerPage);

        $eventBookingIds = [];

        foreach ($paymentsData as &$paymentData) {
            if (empty($paymentData['serviceId']) && empty($paymentData['packageId'])) {
                $eventBookingIds[] = $paymentData['customerBookingId'];
            }
            $paymentData['secondaryPayments'] = $paymentRepository->getSecondaryPayments($paymentData['packageCustomerId'] ?: $paymentData['customerBookingId'], $paymentData['id'], !empty($paymentData['packageCustomerId']));
        }

        /** @var Collection $events */
        $events = !empty($eventBookingIds) ? $eventRepository->getByBookingIds($eventBookingIds) : new Collection();

        $paymentDataValues = array_values($paymentsData);

        $bookingsIds = array_column($paymentDataValues, 'customerBookingId');

        /** @var Event $event */
        foreach ($events->getItems() as $event) {
            /** @var CustomerBooking $booking */
            foreach ($event->getBookings()->getItems() as $booking) {
                if (($key = array_search($booking->getId()->getValue(), $bookingsIds)) !== false) {
                    $paymentsData[$paymentDataValues[$key]['id']]['bookingStart'] =
                        $event->getPeriods()->getItem(0)->getPeriodStart()->getValue()->format('Y-m-d H:i:s');

                    /** @var Provider $provider */
                    foreach ($event->getProviders()->getItems() as $provider) {
                        $paymentsData[$paymentDataValues[$key]['id']]['providers'][] = [
                            'id' => $provider->getId()->getValue(),
                            'fullName' => $provider->getFullName(),
                            'email' => $provider->getEmail()->getValue(),
                        ];
                    }

                    $paymentsData[$paymentDataValues[$key]['id']]['eventId'] = $event->getId()->getValue();

                    $paymentsData[$paymentDataValues[$key]['id']]['name'] = $event->getName()->getValue();

                    if ($event->getCustomPricing() && $event->getCustomPricing()->getValue()) {
                        /** @var CustomerBookingEventTicketRepository $bookingEventTicketRepository */
                        $bookingEventTicketRepository = $this->container->get('domain.booking.customerBookingEventTicket.repository');
                        $price = $bookingEventTicketRepository->calculateTotalPrice($paymentsData[$paymentDataValues[$key]['id']]['customerBookingId']);
                        if ($price) {
                            $paymentsData[$paymentDataValues[$key]['id']]['bookedPrice'] = $price;
                        }
                        $paymentsData[$paymentDataValues[$key]['id']]['aggregatedPrice'] = 0;
                    }
                }
            }
        }

        $packageApplicationService->setPaymentData($paymentsData);

        foreach ($paymentsData as $index => $value) {
            !empty($paymentsData[$index]['providers']) ?
                $paymentsData[$index]['providers'] = array_values($paymentsData[$index]['providers']) : [];
        }

        foreach ($paymentsData as &$item) {
            if (!empty($item['wcOrderId']) && WooCommerceService::isEnabled()) {
                $item['wcOrderUrl'] = HelperService::getWooCommerceOrderUrl($item['wcOrderId']);

                $wcOrderItemValues = HelperService::getWooCommerceOrderItemAmountValues($item['wcOrderId']);

                if ($wcOrderItemValues) {
                    $item['wcItemCouponValue'] = $wcOrderItemValues[0]['coupon'];

                    $item['wcItemTaxValue'] = $wcOrderItemValues[0]['tax'];
                }
            }
        }

        return $paymentsData;
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param CommandResult $result
     * @param array         $paymentData
     * @param Reservation   $reservation
     * @param BookingType   $bookingType
     * @param $paymentTransactionId
     *
     * @return boolean
     *
     * @throws ContainerValueNotFoundException
     * @throws Exception
     * @throws \Interop\Container\Exception\ContainerException
     */
    public function processPayment($result, $paymentData, $reservation, $bookingType, &$paymentTransactionId)
    {
        /** @var ReservationServiceInterface $reservationService */
        $reservationService = $this->container->get('application.reservation.service')->get($bookingType->getValue());

        $paymentAmount = $reservationService->getReservationPaymentAmount($reservation);

        if (!$paymentAmount &&
            (
                $paymentData['gateway'] === 'stripe' ||
                $paymentData['gateway'] === 'payPal'
                || $paymentData['gateway'] === 'mollie'
                || $paymentData['gateway'] === 'razorpay'
            )) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'paymentSuccessful' => false,
                    'onSitePayment'     => true
                ]
            );

            return false;
        }

        switch ($paymentData['gateway']) {
            case ('payPal'):
                /** @var PayPalService $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.payPal.service');

                $response = $paymentService->complete(
                    [
                        'transactionReference' => $paymentData['data']['transactionReference'],
                        'PayerID'              => $paymentData['data']['PayerId'],
                        'amount'               => $paymentAmount,
                    ]
                );

                if ($response->isSuccessful()) {
                    $paymentTransactionId = $response->getData()['id'];
                } else {
                    $result->setResult(CommandResult::RESULT_ERROR);
                    $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
                    $result->setData(
                        [
                            'paymentSuccessful' => false,
                            'message'           => $response->getMessage(),
                        ]
                    );

                    return false;
                }

                return true;

            case ('stripe'):
                /** @var StripeService $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.stripe.service');

                /** @var CurrencyService $currencyService */
                $currencyService = $this->container->get('infrastructure.payment.currency.service');

                $additionalInformation = $this->getBookingInformationForPaymentSettings(
                    $reservation,
                    PaymentType::STRIPE
                );

                try {
                    $response = $paymentService->execute(
                        [
                            'paymentMethodId' => !empty($paymentData['data']['paymentMethodId']) ?
                                $paymentData['data']['paymentMethodId'] : null,
                            'paymentIntentId' => !empty($paymentData['data']['paymentIntentId']) ?
                                $paymentData['data']['paymentIntentId'] : null,
                            'amount'          => $currencyService->getAmountInFractionalUnit(new Price($paymentAmount)),
                            'metaData'        => $additionalInformation['metaData'],
                            'description'     => $additionalInformation['description']
                        ]
                    );
                } catch (Exception $e) {
                    $result->setResult(CommandResult::RESULT_ERROR);
                    $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
                    $result->setData(
                        [
                            'paymentSuccessful' => false,
                            'message'           => $e->getMessage(),
                        ]
                    );

                    return false;
                }

                if (isset($response['requiresAction'])) {
                    $result->setResult(CommandResult::RESULT_SUCCESS);
                    $result->setData(
                        [
                            'paymentIntentClientSecret' => $response['paymentIntentClientSecret'],
                            'requiresAction'            => $response['requiresAction']
                        ]
                    );

                    return false;
                }

                if (empty($response['paymentSuccessful'])) {
                    $result->setResult(CommandResult::RESULT_ERROR);
                    $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
                    $result->setData(
                        [
                            'paymentSuccessful' => false
                        ]
                    );

                    return false;
                }

                $paymentTransactionId = $response['paymentIntentId'];

                return true;

            case ('onSite'):
                if ($paymentAmount &&
                    (
                        $reservation->getLoggedInUser() &&
                        $reservation->getLoggedInUser()->getType() === Entities::CUSTOMER
                    ) &&
                    !$this->isAllowedOnSitePaymentMethod($this->getAvailablePayments($reservation->getBookable()))
                ) {
                    return false;
                }

                return true;

            case ('wc'):
            case ('mollie'):
                return true;
            case ('razorpay'):
                /** @var RazorpayService $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.razorpay.service');

                $paymentId = $paymentData['data']['paymentId'];
                $signature = $paymentData['data']['signature'];
                $orderId   = $paymentData['data']['orderId'];

                try {
                    $attributes = array(
                        'razorpay_order_id'   => $orderId,
                        'razorpay_payment_id' => $paymentId,
                        'razorpay_signature'  => $signature
                    );

                    $paymentService->verify($attributes);
                } catch (SignatureVerificationError $e) {
                    return false;
                }

                $paymentTransactionId = $paymentData['data']['paymentId'];

                $response = $paymentService->capture($paymentData['data']['paymentId'], $paymentAmount);

                if (!$response || $response['error_code']) {
                    return false;
                }

                return true;
        }

        return false;
    }

    /**
     * @param AbstractBookable $bookable
     *
     * @return array
     *
     * @throws ContainerValueNotFoundException
     */
    public function getAvailablePayments($bookable)
    {
        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $generalPayments = $settingsService->getCategorySettings('payments');

        if ($bookable->getSettings()) {
            $hasAvailablePayments = false;

            $bookableSettings = json_decode($bookable->getSettings()->getValue(), true);

            if ($generalPayments['onSite'] === true &&
                isset($bookableSettings['payments']['onSite']) &&
                $bookableSettings['payments']['onSite'] === true
            ) {
                $hasAvailablePayments = true;
            }

            if ($generalPayments['payPal']['enabled'] === true &&
                isset($bookableSettings['payments']['payPal']['enabled']) &&
                $bookableSettings['payments']['payPal']['enabled'] === true
            ) {
                $hasAvailablePayments = true;
            }

            if ($generalPayments['stripe']['enabled'] === true &&
                isset($bookableSettings['payments']['stripe']['enabled']) &&
                $bookableSettings['payments']['stripe']['enabled'] === true
            ) {
                $hasAvailablePayments = true;
            }

            if ($generalPayments['mollie']['enabled'] === true &&
                isset($bookableSettings['payments']['mollie']['enabled']) &&
                $bookableSettings['payments']['mollie']['enabled'] === false &&
                $bookableSettings['payments']['onSite'] === true
            ) {
                $hasAvailablePayments = true;
            }

            return $hasAvailablePayments ? $bookableSettings['payments'] : $generalPayments;
        }

        return $generalPayments;
    }

    /**
     * @param array $bookablePayments
     *
     * @return boolean
     *
     * @throws ContainerException
     * @throws \InvalidArgumentException
     * @throws ContainerValueNotFoundException
     */
    public function isAllowedOnSitePaymentMethod($bookablePayments)
    {
        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $payments = $settingsService->getCategorySettings('payments');

        if ($payments['onSite'] === false &&
            (isset($bookablePayments['onSite']) ? $bookablePayments['onSite'] === false : true)
        ) {
            /** @var AbstractUser $user */
            $user = $this->container->get('logged.in.user');

            if ($user === null || $user->getType() === Entities::CUSTOMER) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Reservation|array $reservation
     * @param string $paymentType
     *
     * @return array
     *
     * @throws ContainerValueNotFoundException
     * @throws \Interop\Container\Exception\ContainerException
     * @throws InvalidArgumentException
     */
    public function getBookingInformationForPaymentSettings($reservation, $paymentType, $bookingIndex = null)
    {
        $reservationType = $reservation instanceof Reservation ? $reservation->getReservation()->getType()->getValue() : $reservation['type'];

        /** @var PlaceholderService $placeholderService */
        $placeholderService = $this->container->get("application.placeholder.{$reservationType}.service");

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $paymentsSettings = $settingsService->getSetting('payments', $paymentType);

        $setDescription = !empty($paymentsSettings['description']);

        $setName = !empty($paymentsSettings['name']);

        $setMetaData = !empty($paymentsSettings['metaData']);

        $placeholderData = [];

        if ($setDescription || $setMetaData || $setName) {
            $reservationData = $reservation;
            if ($reservation instanceof Reservation) {
                $reservationData = $reservation->getReservation()->toArray();

                $reservationData['bookings'] = $reservation->getBooking() ? [
                    $reservation->getBooking()->getId() ?
                        $reservation->getBooking()->getId()->getValue() : 0 => $reservation->getBooking()->toArray()
                ] : [];

                $reservationData['customer'] = $reservation->getCustomer()->toArray();
                $customer  = $reservation->getCustomer();
                $bookingId = $reservation->getBooking() && $reservation->getBooking()->getId() ? $reservation->getBooking()->getId()->getValue() : 0;
            } else {
                $customer  = UserFactory::create($reservation['bookings'][$bookingIndex]['customer']);
                $bookingId = $bookingIndex;
            }

            try {
                $placeholderData = $placeholderService->getPlaceholdersData(
                    $reservationData,
                    $bookingId,
                    null,
                    $customer
                );
            } catch (Exception $e) {
                $placeholderData = [];
            }
        }

        $metaData = [];

        $description = '';
        $name        = '';

        if ($placeholderData && $setDescription) {
            $description = $placeholderService->applyPlaceholders(
                $paymentsSettings['description'][$reservationType],
                $placeholderData
            );
        }

        if ($placeholderData && $setName) {
            $name = $placeholderService->applyPlaceholders(
                $paymentsSettings['name'][$reservationType],
                $placeholderData
            );
        }

        if ($placeholderData && $setMetaData) {
            foreach ((array)$paymentsSettings['metaData'][$reservationType] as $metaDataKay => $metaDataValue) {
                $metaData[$metaDataKay] = $placeholderService->applyPlaceholders(
                    $metaDataValue,
                    $placeholderData
                );
            }
        }

        return [
            'description' => $description,
            'metaData'    => $metaData,
            'name'        => $name
        ];
    }

    /**
     * @param Payment $payment
     *
     * @return boolean
     *
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     */
    public function delete($payment)
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        /** @var CacheRepository $cacheRepository */
        $cacheRepository = $this->container->get('domain.cache.repository');

        /** @var Collection $followingPayments */
        $followingPayments = $paymentRepository->getByEntityId(
            $payment->getId()->getValue(),
            'parentId'
        );

        /** @var Collection $caches */
        $caches = $cacheRepository->getByEntityId(
            $payment->getId()->getValue(),
            'paymentId'
        );

        /** @var Cache $cache */
        foreach ($caches->getItems() as $cache) {
            /** @var Payment $nextPayment */
            $nextPayment = $followingPayments->length() ? $followingPayments->getItem(0) : null;

            if ($nextPayment) {
                $cacheRepository->updateByEntityId(
                    $payment->getId()->getValue(),
                    $nextPayment->getId()->getValue(),
                    'paymentId'
                );
            } else {
                $cacheRepository->updateFieldById(
                    $cache->getId()->getValue(),
                    null,
                    'paymentId'
                );
            }
        }

        if (!$paymentRepository->delete($payment->getId()->getValue())) {
            return false;
        }

        return true;
    }

    /**
     * @param CustomerBooking $booking
     *
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     */
    public function updateBookingPaymentDate($booking, $date)
    {
        foreach ($booking->getPayments()->getItems() as $payment) {
            if ($payment->getGateway()->getName()->getValue() === PaymentType::ON_SITE) {
                /** @var PaymentRepository $paymentRepository */
                $paymentRepository = $this->container->get('domain.payment.repository');

                $paymentRepository->updateFieldById(
                    $payment->getId()->getValue(),
                    $date,
                    'dateTime'
                );
            }
        }
    }

    /**
     * @param array $data
     * @param int $amount
     * @param string $type
     *
     * @return Payment
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws \Interop\Container\Exception\ContainerException
     * @throws Exception
     */
    public function insertPaymentFromLink($originalPayment, $amount, $type)
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        $linkPayment = PaymentFactory::create($originalPayment);
        $linkPayment->setAmount(new Price($amount));
        $linkPayment->setId(null);
        $linkPayment->setDateTime(null);
        $linkPayment->setEntity(new Name($type));
        $linkPayment->setActionsCompleted(new BooleanValueObject(true));
        if ($type === Entities::PACKAGE) {
            $linkPayment->setCustomerBookingId(null);
            $linkPayment->setPackageCustomerId(new Id($originalPayment['packageCustomerId']));
        }
        $linkPaymentId = $paymentRepository->add($linkPayment);
        $linkPayment->setId(new Id($linkPaymentId));
        return $linkPayment;
    }

    /**
     * @param array $data
     * @param int $index
     * @param string|null $paymentMethod
     *
     * @return array
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws \Interop\Container\Exception\ContainerException
     * @throws Exception
     */
    public function createPaymentLink($data, $index = null, $recurringKey = null, $paymentMethod = null)
    {
        try {
            /** @var PaymentApplicationService $paymentAS */
            $paymentAS = $this->container->get('application.payment.service');
            /** @var SettingsService $settingsService */
            $settingsService = $this->container->get('domain.settings.service');
            /** @var PaymentRepository $paymentRepository */
            $paymentRepository = $this->container->get('domain.payment.repository');

            $type        = $data['type'];
            $reservation = $data[$type];
            $booking     = $recurringKey !== null ? $data['recurring'][$recurringKey]['bookings'][$index] : $data['booking'];

            $reservation['bookings'][$index]['customer'] = $data['customer'];
            $customer = $data['customer'];
            $reservation['packageCustomerId'] = !empty($data['packageCustomerId']) ? $data['packageCustomerId'] : null;

            $entitySettings       = !empty($data['bookable']) && !empty($data['bookable']['settings']) && json_decode($data['bookable']['settings'], true) ? json_decode($data['bookable']['settings'], true) : null;
            $paymentLinksSettings = !empty($entitySettings) && !empty($entitySettings['payments']['paymentLinks']) ? $entitySettings['payments']['paymentLinks'] : null;
            $paymentLinksEnabled  = $paymentLinksSettings ? $paymentLinksSettings['enabled'] : $settingsService->getSetting('payments', 'paymentLinks')['enabled'];
            if (!$paymentLinksEnabled) {
                return null;
            }

            $paymentLinksSettings = !empty($entitySettings) ? $entitySettings['payments']['paymentLinks'] : null;
            $paymentLinksEnabled  = $paymentLinksSettings ? $paymentLinksSettings['enabled'] : $settingsService->getSetting('payments', 'paymentLinks')['enabled'];
            if (!$paymentLinksEnabled || ($booking && (in_array($booking['status'], [BookingStatus::CANCELED, BookingStatus::REJECTED, BookingStatus::NO_SHOW])))) {
                return null;
            }

            $redirectUrl = $paymentLinksSettings && $paymentLinksSettings['redirectUrl'] ? $paymentLinksSettings['redirectUrl'] :
                $settingsService->getSetting('payments', 'paymentLinks')['redirectUrl'];
            $redirectUrl = empty($redirectUrl) ? AMELIA_SITE_URL : $redirectUrl;

            $customerPanelUrl = $settingsService->getSetting('roles', 'customerCabinet')['pageUrl'];
            $redirectUrl      = $paymentMethod ? $customerPanelUrl : $redirectUrl;

            $totalPrice = $this->calculateAppointmentPrice($booking, $type, $reservation);

            $oldPaymentId = $recurringKey !== null ? $data['recurring'][$recurringKey]['bookings'][$index]['payments'][0]['id'] : $data['paymentId'];

            if (!empty($data['packageCustomerId'])) {
                $payments = $paymentRepository->getByEntityId($data['packageCustomerId'], 'packageCustomerId');
            } else {
                $payments = $paymentRepository->getByEntityId($booking['id'], 'customerBookingId');
            }

            if (empty($payments)  || $payments->length() === 0 || empty($oldPaymentId)) {
                return null;
            }

            $payments   = $payments->toArray();
            $allAmounts = 0;
            foreach ($payments as $payment) {
                if ($payment['status'] !== 'refunded') {
                    $allAmounts += $payment['amount'];
                }
            }
            $allWCTaxes = array_sum(array_filter(array_column($payments, 'wcItemTaxValue')));

            $amountWithoutTax = $allAmounts - $allWCTaxes;
            if ($amountWithoutTax >= $totalPrice || $totalPrice === 0) {
                return null;
            }

            $oldPaymentKey = array_search($oldPaymentId, array_column($payments, 'id'));
            if ($oldPaymentKey === false) {
                return null;
            }
            $oldPayment = $payments[$oldPaymentKey];

            $amount = $totalPrice - $amountWithoutTax;

            $callbackLink = AMELIA_ACTION_URL . '/payments/callback&fromLink=true&paymentAmeliaId=' . $oldPaymentId . '&chargedAmount=' . $amount . '&fromPanel=' . (!empty($paymentMethod));

            $paymentSettings = $settingsService->getCategorySettings('payments');

            $paymentLinks = [];

            $methods = $paymentMethod ?: [
                'payPal'   => !empty($entitySettings) && !empty($entitySettings['payments']['payPal']) ? ($entitySettings['payments']['payPal']['enabled'] && $paymentSettings['payPal']['enabled']) : $paymentSettings['payPal']['enabled'],
                'stripe'   => !empty($entitySettings) && !empty($entitySettings['payments']['stripe']) ? ($entitySettings['payments']['stripe']['enabled'] && $paymentSettings['stripe']['enabled']) : $paymentSettings['stripe']['enabled'],
                'razorpay' => !empty($entitySettings) && !empty($entitySettings['payments']['razorpay']) ? ($entitySettings['payments']['razorpay']['enabled'] && $paymentSettings['razorpay']['enabled']) : $paymentSettings['razorpay']['enabled'],
                'mollie'   => !empty($entitySettings) && !empty($entitySettings['payments']['mollie']) ? ($entitySettings['payments']['mollie']['enabled'] && $paymentSettings['mollie']['enabled']) : $paymentSettings['mollie']['enabled'],
                'wc'       => $paymentSettings['wc']['enabled']
            ];

            if (!empty($methods['wc'])) {
                /** @var ReservationServiceInterface $reservationService */
                $reservationService = $this->container->get('application.reservation.service')->get($type);

                $appointmentData = $reservationService->getWooCommerceDataFromArray($data, $index);
                $appointmentData['redirectUrl'] = $redirectUrl;

                $linkPayment = PaymentFactory::create($oldPayment);

                $linkPayment->setStatus(new PaymentStatus(PaymentStatus::PENDING));
                $linkPayment->setDateTime(null);
                $linkPayment->setWcOrderId(null);
                $linkPayment->setGatewayTitle(null);
                $linkPayment->setEntity(new Name($type));
                $linkPayment->setActionsCompleted(new BooleanValueObject(true));
                if ($type === Entities::PACKAGE) {
                    $linkPayment->setCustomerBookingId(null);
                    $linkPayment->setPackageCustomerId(new Id($data['packageCustomerId']));
                }


                $appointmentData['payment'] = $linkPayment->toArray();
                $appointmentData['payment']['fromLink']   = true;
                $appointmentData['payment']['newPayment'] = $oldPayment['gateway'] !== 'onSite';

                $bookableSettings = $data['bookable']['settings'] ?
                    json_decode($data['bookable']['settings'], true) : null;

                $productId = $bookableSettings && isset($bookableSettings['payments']['wc']) && isset($bookableSettings['payments']['wc']['productId']) ?
                    $bookableSettings['payments']['wc']['productId'] : $settingsService->getCategorySettings('payments')['wc']['productId'];

                $orderId = WooCommerceService::createWcOrder($productId, $appointmentData, $amount, $oldPayment['wcOrderId'], $customer);

                $paymentLink = WooCommerceService::getPaymentLink($orderId);
                if (!empty($paymentLink['link'])) {
                    $paymentLinks['payment_link_woocommerce'] = $paymentLink['link'];
                }

                return $paymentLinks;
            }

            if (!empty($methods['payPal'])) {
                /** @var PaymentServiceInterface $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.payPal.service');

                $additionalInformation = $paymentAS->getBookingInformationForPaymentSettings($reservation, PaymentType::PAY_PAL, $index);

                $paymentData = [
                    'amount'      => $amount,
                    'description' => $additionalInformation['description'],
                    'returnUrl'   => $callbackLink . '&paymentMethod=payPal&payPalStatus=success',
                    'cancelUrl'   => $callbackLink . '&paymentMethod=payPal&payPalStatus=canceled'
                ];

                $paymentLink = $paymentService->getPaymentLink($paymentData);
                if ($paymentLink['status'] === 200 && !empty($paymentLink['link'])) {
                    $paymentLinks['payment_link_paypal'] = $paymentLink['link'] . '&useraction=commit';
                } else {
                    $paymentLinks['payment_link_paypal_error_code']    = $paymentLink['status'];
                    $paymentLinks['payment_link_paypal_error_message'] = $paymentLink['message'];
                }
            }

            if (!empty($methods['stripe'])) {
                /** @var PaymentServiceInterface $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.stripe.service');
                /** @var CurrencyService $currencyService */
                $currencyService = $this->container->get('infrastructure.payment.currency.service');

                $additionalInformation = $paymentAS->getBookingInformationForPaymentSettings($reservation, PaymentType::STRIPE, $index);

                $paymentData = [
                    'amount'      => $currencyService->getAmountInFractionalUnit(new Price($amount)),
                    'description' => $additionalInformation['description'] ?: $data['bookable']['name'],
                    'returnUrl'   => $callbackLink . '&paymentMethod=stripe',
                    'metaData'    => $additionalInformation['metaData'] ?: [],
                    'currency'    => $settingsService->getCategorySettings('payments')['currency'],
                ];

                $paymentLink = $paymentService->getPaymentLink($paymentData);
                if ($paymentLink['status'] === 200 && !empty($paymentLink['link'])) {
                    $paymentLinks['payment_link_stripe'] = $paymentLink['link'] . '?prefilled_email=' . $customer['email'];
                } else {
                    $paymentLinks['payment_link_stripe_error_code']    = $paymentLink['status'];
                    $paymentLinks['payment_link_stripe_error_message'] = $paymentLink['message'];
                }
            }

            if (!empty($methods['mollie'])) {
                /** @var PaymentServiceInterface $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.mollie.service');

                $additionalInformation = $paymentAS->getBookingInformationForPaymentSettings($reservation, PaymentType::MOLLIE, $index);

                $info        = json_decode($booking['info'], true);
                $paymentData =
                    [
                        'amount'      => [
                            'currency' =>  $settingsService->getCategorySettings('payments')['currency'],
                            'value' => number_format((float)$amount, 2, '.', '')//strval($amount)
                        ],
                        'description' => $additionalInformation['description'] ?: $data['bookable']['name'],
                        'redirectUrl' => $redirectUrl,
                        'webhookUrl'  => (AMELIA_DEV ? str_replace('localhost', AMELIA_NGROK_URL, $callbackLink) : $callbackLink) . '&paymentMethod=mollie',
//                    'locale'      => str_replace('-', '_', $info['locale']),
//                    'method'      => $settingsService->getSetting('payments', 'mollie')['method'],
//                    'metaData'    => $additionalInformation['metaData'] ?: [],
                ];

                $paymentLink = $paymentService->getPaymentLink($paymentData);
                if ($paymentLink['status'] === 200 && !empty($paymentLink['link'])) {
                    $paymentLinks['payment_link_mollie'] = $paymentLink['link'];
                } else {
                    $paymentLinks['payment_link_mollie_error_code']    = $paymentLink['status'];
                    $paymentLinks['payment_link_mollie_error_message'] = $paymentLink['message'];
                }
            }


            if (!empty($methods['razorpay'])) {
                /** @var PaymentServiceInterface $paymentService */
                $paymentService = $this->container->get('infrastructure.payment.razorpay.service');

                $additionalInformation = $paymentAS->getBookingInformationForPaymentSettings($reservation, PaymentType::RAZORPAY, $index);

                $paymentData =
                    [
                        'amount'      => intval($amount * 100),
                        'description' => $additionalInformation['description'],
                        'notes'    => $additionalInformation['metaData'] ?: [],
                        'currency' => $settingsService->getCategorySettings('payments')['currency'],
                        'customer' => [
                            'name'    => $customer['firstName'] . ' ' . $customer['lastName'],
                            'email'   => $customer['email'],
                            'contact' => $customer['phone']
                        ],
                        //'notify' => ['sms' => false, 'email' => true],
                        'callback_url'    => AMELIA_ACTION_URL . '__payments__callback&fromLink=true&paymentAmeliaId=' . $oldPaymentId . '&chargedAmount=' . $amount . '&paymentMethod=razorpay' . '&fromPanel=' . (!empty($paymentMethod)),
                        'callback_method' => 'get'
                    ];

                $paymentLink = $paymentService->getPaymentLink($paymentData);
                if ($paymentLink['status'] === 200 && !empty($paymentLink['link'])) {
                    $paymentLinks['payment_link_razorpay'] = $paymentLink['link'];
                } else {
                    $paymentLinks['payment_link_razorpay_error_code']    = $paymentLink['status'];
                    $paymentLinks['payment_link_razorpay_error_message'] = $paymentLink['message'];
                }
            }

            return $paymentLinks;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @param array  $booking
     * @param string $type
     * @return float
     */
    public function calculateAppointmentPrice($booking, $type, $reservationEntity = null)
    {
        if ($type === Entities::PACKAGE) {
            $price          = $reservationEntity['price'];
            $couponDiscount = 0;

            if (!$reservationEntity['calculatedPrice'] && $reservationEntity['discount']) {
                $subtraction = $price / 100 * ($reservationEntity['discount'] ?: 0);

                $price = (float)round($price - $subtraction, 2);
            }

            if (!!$reservationEntity['packageCustomerId']) {
                /** @var PackageCustomerRepository $packageCustomerRepository */
                $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

                $packageCustomer = $packageCustomerRepository->getById($reservationEntity['packageCustomerId']) ?
                    $packageCustomerRepository->getById($reservationEntity['packageCustomerId'])->toArray() : null;

                if ($packageCustomer && $packageCustomer['couponId']) {

                    /** @var CouponRepository $couponRepository */
                    $couponRepository = $this->container->get('domain.coupon.repository');

                    $coupon = $couponRepository->getById($packageCustomer['couponId']) ?
                        $couponRepository->getById($packageCustomer['couponId'])->toArray() : null;

                    if ($coupon) {
                        $couponDiscount = $price / 100 *
                            ($coupon['discount'] ?: 0) +
                            ($coupon['deduction'] ?: 0);
                    }
                }
            }

            return round($price - $couponDiscount, 2);
        }

        $isAggregatedPrice = isset($booking['aggregatedPrice']) &&
            $booking['aggregatedPrice'];

        $appointmentPrice = $booking['price'] *
            ($isAggregatedPrice ? $booking['persons'] : 1);

        if ($type === Entities::APPOINTMENT) {
            foreach ((array)$booking['extras'] as $extra) {
                $isExtraAggregatedPrice = !empty($extra['aggregatedPrice']);

                $extra['price']    = isset($extra['price']) ? $extra['price'] : 0;
                $appointmentPrice +=
                    $extra['price'] *
                    $extra['quantity'] *
                    ($isExtraAggregatedPrice ? $booking['persons'] : 1);
            }
        }

        if ($type === Entities::EVENT) {
            if (!empty($booking['ticketsData'])) {
                $ticketsPrice = 0;
                foreach ($booking['ticketsData'] as $key => $bookingToEventTicket) {
                    if ($bookingToEventTicket['price']) {
                        $ticketsPrice +=
                            ($isAggregatedPrice ? $bookingToEventTicket['persons'] : 1) * $bookingToEventTicket['price'];
                    }
                }
                $appointmentPrice = $ticketsPrice;
            }
        }

        if (!empty($booking['coupon']['discount'])) {
            $appointmentPrice = (1 - $booking['coupon']['discount'] / 100) * $appointmentPrice;
        }
        if (!empty($booking['coupon']['deduction'])) {
            $deductionValue    = $booking['coupon']['deduction'];
            $appointmentPrice -= $deductionValue;
        }

        return $appointmentPrice;
    }


    /**
     * @param array  $booking
     * @param string $type
     * @return string
     */
    public function getFullStatus($booking, $type)
    {
        $bookingPrice = $this->calculateAppointmentPrice($booking, $type); //add wc tax
        $paidAmount   = array_sum(
            array_column(
                array_filter(
                    $booking['payments'],
                    function ($value) {
                        return $value['status'] !== 'pending';
                    }
                ),
                'amount'
            )
        );
        if ($paidAmount >= $bookingPrice) {
            return 'paid';
        }
        $partialPayments = array_filter(
            $booking['payments'],
            function ($value) {
                return $value['status'] === 'partiallyPaid';
            }
        );
        return !empty($partialPayments) ? 'partiallyPaid' : 'pending';
    }


    /**
     * @param array $paymentData
     * @param int $paymentId
     * @param string $transactionId
     *
     * @throws QueryExecutionException
     */
    public function setPaymentTransactionId($paymentId, $transactionId)
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        if ($transactionId && $paymentId) {
            $paymentRepository->updateTransactionId(
                $paymentId,
                $transactionId
            );
        }
    }
}
