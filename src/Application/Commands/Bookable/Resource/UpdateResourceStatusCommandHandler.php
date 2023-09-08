<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Resource;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ResourceRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class UpdateResourceStatusCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Resource
 */
class UpdateResourceStatusCommandHandler extends CommandHandler
{
    /**
     * @var array
     */
    public $mandatoryFields = [
        'status',
    ];

    /**
     * @param UpdateResourceStatusCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     * @throws QueryExecutionException
     */
    public function handle(UpdateResourceStatusCommand $command)
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanWrite(Entities::RESOURCES)) {
            throw new AccessDeniedException('You are not allowed to update resource.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var ResourceRepository $resourceRepository */
        $resourceRepository = $this->container->get('domain.bookable.resource.repository');

        $resourceRepository->updateStatusById(
            $command->getArg('id'),
            $command->getField('status')
        );

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully updated resource');
        $result->setData(true);

        return $result;
    }
}
