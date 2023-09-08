<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Bookable\PackageApplicationService;
use AmeliaBooking\Application\Services\User\UserApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\Service\Package;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomer;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomerService;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Factory\Bookable\Service\PackageCustomerFactory;
use AmeliaBooking\Domain\Factory\Payment\PaymentFactory;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Reservation\ReservationServiceInterface;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\String\Name;
use AmeliaBooking\Domain\ValueObjects\String\PaymentType;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageRepository;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class AddPackageCustomerCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Package
 */
class AddPackageCustomerCommandHandler extends CommandHandler
{
    /**
     * @var array
     */
    public $mandatoryFields = [
        'packageId',
        'customerId',
        'rules'
    ];

    /**
     * @param AddPackageCustomerCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     * @throws QueryExecutionException
     * @throws NotFoundException
     */
    public function handle(AddPackageCustomerCommand $command)
    {
        $result = new CommandResult();

        /** @var UserApplicationService $userAS */
        $userAS = $this->getContainer()->get('application.user.service');

        /** @var AbstractUser $user */
        $user = null;

        if (!$this->getContainer()->getPermissionsService()->currentUserCanWrite(Entities::PACKAGES)) {
            /** @var AbstractUser $user */
            $user = $userAS->getAuthenticatedUser($command->getToken(), false, 'customerCabinet');

            if ($user === null) {
                $result->setResult(CommandResult::RESULT_ERROR);
                $result->setMessage('Could not retrieve user');
                $result->setData(
                    [
                        'reauthorize' => true
                    ]
                );

                return $result;
            }
        }

        $this->checkMandatoryFields($command);

        /** @var PackageApplicationService $packageApplicationService */
        $packageApplicationService = $this->container->get('application.bookable.package');

        /** @var PackageRepository $packageRepository */
        $packageRepository = $this->container->get('domain.bookable.package.repository');

        /** @var ReservationServiceInterface $reservationService */
        $reservationService = $this->container->get('application.reservation.service')->get(
            Entities::PACKAGE
        );


        /** @var Package $package */
        $package = $packageRepository->getById($command->getField('packageId'));


        /** @var PackageCustomer $packageCustomer */
        $packageCustomer = $packageApplicationService->addPackageCustomer(
            $package,
            $command->getField('customerId'),
            null,
            $reservationService->getPaymentAmount(null, $package),
            true,
            null
        );

        /** @var Collection $packageCustomerServices */
        $packageCustomerServices = $packageApplicationService->addPackageCustomerServices(
            $package,
            $packageCustomer,
            $command->getField('rules'),
            true
        );

        $onlyOneEmployee = $packageApplicationService->getOnlyOneEmployee($package);

        /** @var Payment $payment */
        $payment = $reservationService->addPayment(
            null,
            $packageCustomer->getId()->getValue(),
            [
                'isBackendBooking' => true,
                'gateway' => PaymentType::ON_SITE
            ],
            !empty($package->getPrice()) ? $package->getPrice()->getValue() : 0,
            DateTimeService::getNowDateTimeObject(),
            Entities::PACKAGE
        );

        $payments = new Collection();
        $payments->addItem($payment, $payment->getId()->getValue());
        $packageCustomer->setPayments($payments);


        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully added new package booking.');
        $result->setData(
            [
                'packageCustomerId' => $packageCustomer->getId() ? $packageCustomer->getId()->getValue() : null,
                'notify' => $command->getField('notify'),
                'paymentId' => $payment->getId()->getValue(),
                'onlyOneEmployee' => $onlyOneEmployee
            ]
        );


        return $result;
    }
}
