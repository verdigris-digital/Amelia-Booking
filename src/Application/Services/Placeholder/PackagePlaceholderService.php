<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Services\Placeholder;

use AmeliaBooking\Application\Services\Helper\HelperService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomer;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomerService;
use AmeliaBooking\Domain\Entity\Coupon\Coupon;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Factory\User\UserFactory;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\PaymentStatus;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerServiceRepository;
use AmeliaBooking\Infrastructure\Repository\Coupon\CouponRepository;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;
use Exception;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class PackagePlaceholderService
 *
 * @package AmeliaBooking\Application\Services\Notification
 */
class PackagePlaceholderService extends AppointmentPlaceholderService
{
    /**
     *
     * @return array
     *
     * @throws ContainerException
     */
    public function getEntityPlaceholdersDummyData($type)
    {
        /** @var HelperService $helperService */
        $helperService = $this->container->get('application.helper.service');

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        /** @var PlaceholderService $placeholderService */
        $placeholderService = $this->container->get("application.placeholder.appointment.service");

        $dateFormat = $settingsService->getSetting('wordpress', 'dateFormat');

        return array_merge([
            'package_name'                => 'Package Name',
            'reservation_name'            => 'Package Name',
            'package_price'               => $helperService->getFormattedPrice(100),
            'package_deposit_payment'     => $helperService->getFormattedPrice(20),
            'package_description'         => 'Package Description',
            'package_duration'            => date_i18n($dateFormat, date_create()->getTimestamp()),
            'reservation_description'     => 'Reservation Description'
        ], $placeholderService->getEntityPlaceholdersDummyData($type));
    }

    /**
     * @param array        $package
     * @param int          $bookingKey
     * @param string       $type
     * @param AbstractUser $customer
     *
     * @return array
     *
     * @throws ContainerValueNotFoundException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     * @throws Exception
     */
    public function getPlaceholdersData($package, $bookingKey = null, $type = null, $customer = null, $allBookings = null)
    {
        /** @var HelperService $helperService */
        $helperService = $this->container->get('application.helper.service');

        $locale = !empty($package['isForCustomer']) && !empty($package['customer']['translations']) ?
            $helperService->getLocaleFromTranslations(
                $package['customer']['translations']
            ) : null;

        $paymentLinks = [
            'payment_link_woocommerce' => '',
            'payment_link_stripe' => '',
            'payment_link_paypal' => '',
            'payment_link_razorpay' => '',
            'payment_link_mollie' => '',
        ];

        if (!empty($package['paymentLinks'])) {
            foreach ($package['paymentLinks'] as $paymentType => $paymentLink) {
                $paymentLinks[$paymentType] = $type === 'email' ? '<a href="' . $paymentLink . '">' . $paymentLink . '</a>' : $paymentLink;
            }
        }

        return array_merge(
            $paymentLinks,
            $this->getPackageData($package),
            $this->getCompanyData($locale),
            $this->getCustomersData(
                $package,
                $type,
                0,
                $customer ?: UserFactory::create($package['customer'])
            ),
            $this->getRecurringAppointmentsData($package, $bookingKey, $type, 'package'),
            [
                'icsFiles' => !empty($package['icsFiles']) ? $package['icsFiles'] : []
            ],
            $this->getCouponsData($package, $type, 0)
        );
    }

    /**
     * @param array $package
     *
     * @return array
     *
     * @throws ContainerValueNotFoundException
     * @throws ContainerException
     * @throws Exception
     */
    private function getPackageData($package)
    {
        /** @var HelperService $helperService */
        $helperService = $this->container->get('application.helper.service');

        $price = $package['price'];

        if (!$package['calculatedPrice'] && $package['discount']) {
            $subtraction = $price / 100 * $package['discount'];

            $price = (float)round($price - $subtraction, 2);
        }

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $dateFormat = $settingsService->getSetting('wordpress', 'dateFormat');

        /** @var PackageCustomerServiceRepository $packageCustomerServiceRepository */
        $packageCustomerServiceRepository = $this->container->get('domain.bookable.packageCustomerService.repository');

        /** @var Collection $packageCustomerServices */
        $packageCustomerServices = $packageCustomerServiceRepository->getByCriteria(
            [
                'customerId' => $package['customer']['id'],
                'packages'   => [$package['id']]
            ]
        );

        $coupon = null;

        $endDate = null;

        $paymentType = '';

        $deposit = null;

        /** @var PackageCustomerService $packageCustomerService */
        foreach ($packageCustomerServices->getItems() as $packageCustomerService) {
            if ($packageCustomerService->getPackageCustomer()->getEnd()) {
                if ($endDate === null) {
                    $endDate = $packageCustomerService->getPackageCustomer()->getEnd()->getValue();
                }

                if ($packageCustomerService->getPackageCustomer()->getEnd()->getValue() > $endDate) {
                    $endDate = $packageCustomerService->getPackageCustomer()->getEnd()->getValue();
                }
            }
            if ($packageCustomerService->getPackageCustomer()->getPayments()) {
                $payments = $packageCustomerService->getPackageCustomer()->getPayments()->getItems();
                /** @var Payment $payment */
                foreach ($payments as $index => $payment) {
                    if (!empty($package['deposit']) && $payment->getStatus()->getValue() === PaymentStatus::PARTIALLY_PAID) {
                        $deposit = $payment->getAmount()->getValue();
                    }

                    switch ($payment->getGateway()->getName()->getValue()) {
                        case 'onSite':
                            $method = BackendStrings::getCommonStrings()['on_site'];
                            break;
                        case 'wc':
                            $method = BackendStrings::getSettingsStrings()['wc_name'];
                            break;
                        default:
                            $method = BackendStrings::getSettingsStrings()[$payment->getGateway()->getName()->getValue()];
                            break;
                    }

                    $paymentType .= ($index === array_keys($payments)[0] ? '' : ', ') . $method;
                }
            }

            if ($coupon === null && $packageCustomerService->getPackageCustomer()->getCouponId()) {
                /** @var CouponRepository $couponRepository */
                $couponRepository = $this->container->get('domain.coupon.repository');

                /** @var PackageCustomerRepository $packageCustomerRepository */
                $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

                /** @var PackageCustomer $packageCustomer */
                $packageCustomer = $packageCustomerRepository->getById(
                    $packageCustomerService->getPackageCustomer()->getId()->getValue()
                );

                $couponId = $packageCustomer->getCouponId()->getValue();

                /** @var Coupon $coupon */
                $coupon = $couponRepository->getById($couponId)->toArray();
            }
        }

        /** @var string $break */
        $break = '<p><br></p>';

        $couponsUsed = [];

        $deductionValue = 0;

        $discountValue = 0;

        $expirationDate = null;

        // get coupon for WC description
        if ($coupon === null && isset($package['bookings']) && $package['bookings'][0]['couponId']) {
            /** @var CouponRepository $couponRepository */
            $couponRepository = $this->container->get('domain.coupon.repository');

            $coupon = $couponRepository->getById($package['bookings'][0]['couponId'])->toArray();
        }

        if ($coupon) {
            if (!empty($coupon['deduction'])) {
                $deductionValue = $coupon['deduction'];

                $price -= $coupon['deduction'];
            }

            if (!empty($coupon['discount'])) {
                $discountValue = $price -
                    (1 - $coupon['discount'] / 100) * $price;

                $price =
                    (1 - $coupon['discount'] / 100) * $price;
            }

            if (!empty($coupon['expirationDate'])) {
                $expirationDate = $coupon['expirationDate'];
            }

            $couponsUsed[] =
                $coupon['code'] . ' ' . $break .
                ($discountValue ? BackendStrings::getPaymentStrings()['discount_amount'] . ': ' .
                    $helperService->getFormattedPrice($discountValue) . ' ' . $break : '') .
                ($deductionValue ? BackendStrings::getPaymentStrings()['deduction'] . ': ' .
                    $helperService->getFormattedPrice($deductionValue) . ' ' . $break : '') .
                ($expirationDate ? BackendStrings::getPaymentStrings()['expiration_date'] . ': ' .
                    $expirationDate : '');
        }

        $locale = !empty($package['isForCustomer']) && !empty($package['customer']['translations']) ?
            $helperService->getLocaleFromTranslations(
                $package['customer']['translations']
            ) : null;

        $packageName = $helperService->getBookingTranslation(
            $locale,
            $package['translations'],
            'name'
        ) ?: $package['name'];

        $packageDescription = $helperService->getBookingTranslation(
            $locale,
            $package['translations'],
            'description'
        ) ?: $package['description'];

        return [
            'reservation_name'        => $packageName,
            'package_name'            => $packageName,
            'package_description'     => $packageDescription,
            'package_duration'        => $endDate ?
                date_i18n($dateFormat, $endDate->getTimestamp()) :
                FrontendStrings::getBookingStrings()['package_book_unlimited'],
            'reservation_description' => $packageDescription,
            'package_price'           => $helperService->getFormattedPrice($price),
            'package_deposit_payment' => $deposit !== null ? $helperService->getFormattedPrice($deposit) : '',
            'payment_type'            => $paymentType,
            'coupon_used'             => $couponsUsed ? implode($break, $couponsUsed) : '',
        ];
    }

    /**
     * @param array $entity
     *
     * @param string $subject
     * @param string $body
     * @param int    $userId
     * @return array
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     */
    public function reParseContentForProvider($entity, $subject, $body, $userId)
    {
        $employeeSubject = $subject;

        $employeeBody = $body;

        foreach ($entity['recurring'] as $recurringData) {
            if ($recurringData['appointment']['providerId'] === $userId) {
                $employeeData = $this->getEmployeeData($recurringData['appointment']);

                $employeeSubject = $this->applyPlaceholders(
                    $subject,
                    $employeeData
                );

                $employeeBody = $this->applyPlaceholders(
                    $body,
                    $employeeData
                );
            }
        }
        if (empty($entity['recurring']) && !empty($entity['onlyOneEmployee'])) {
            $employeeData = $this->getEmployeeData(['providerId' => $entity['onlyOneEmployee']['id']]);

            $employeeSubject = $this->applyPlaceholders(
                $subject,
                $employeeData
            );

            $employeeBody = $this->applyPlaceholders(
                $body,
                $employeeData
            );
        }

        return [
            'body'    => $employeeBody,
            'subject' => $employeeSubject,
        ];
    }
}
