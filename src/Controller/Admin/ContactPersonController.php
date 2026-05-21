<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\ContactPersonAdmin;
use App\ContactPerson\ContactPersonManagerInterface;
use App\Entity\ContactPerson;
use FOS\RestBundle\View\ViewHandlerInterface;
use Sulu\Component\Rest\AbstractRestController;
use Sulu\Component\Rest\Exception\EntityNotFoundException;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\Metadata\FieldDescriptorFactoryInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class ContactPersonController extends AbstractRestController
{
    public function __construct(
        ViewHandlerInterface $viewHandler,
        TokenStorageInterface $tokenStorage,
        private readonly ContactPersonManagerInterface $manager,
        private readonly RestHelperInterface $restHelper,
        private readonly FieldDescriptorFactoryInterface $fieldDescriptorFactory,
        private readonly DoctrineListBuilderFactoryInterface $listBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
    ) {
        parent::__construct($viewHandler, $tokenStorage);
    }

    public function cgetAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(
            new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
            PermissionTypes::VIEW
        );

        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(ContactPersonAdmin::LIST_KEY);
        $listBuilder = $this->listBuilderFactory->create(ContactPerson::class);

        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);

        $list = new PaginatedRepresentation(
            $listBuilder->execute(),
            ContactPersonAdmin::RESOURCE_KEY,
            (int) $listBuilder->getCurrentPage(),
            (int) $listBuilder->getLimit(),
            (int) $listBuilder->count()
        );

        return $this->handleView($this->view($list, 200));
    }

    public function getAction(int $id): Response
    {
        $this->securityChecker->checkPermission(
            new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
            PermissionTypes::VIEW
        );

        $contactPerson = $this->manager->getById($id);

        if (!$contactPerson) {
            throw new EntityNotFoundException(ContactPerson::class, $id);
        }

        return $this->handleView($this->view($this->serialize($contactPerson), 200));
    }

    public function postAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(
            new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
            PermissionTypes::ADD
        );

        try {
            $contactPerson = $this->manager->create($request->request->all());
        } catch (ValidationFailedException $e) {
            return $this->handleView($this->view($this->formatViolations($e), 422));
        }

        return $this->handleView($this->view($this->serialize($contactPerson), 201));
    }

    public function putAction(Request $request, int $id): Response
    {
        $this->securityChecker->checkPermission(
            new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
            PermissionTypes::EDIT
        );

        $contactPerson = $this->manager->getById($id);

        if (!$contactPerson) {
            throw new EntityNotFoundException(ContactPerson::class, $id);
        }

        try {
            $this->manager->update($contactPerson, $request->request->all());
        } catch (ValidationFailedException $e) {
            return $this->handleView($this->view($this->formatViolations($e), 422));
        }

        return $this->handleView($this->view($this->serialize($contactPerson), 200));
    }

    public function deleteAction(int $id): Response
    {
        $this->securityChecker->checkPermission(
            new SecurityCondition(ContactPersonAdmin::SECURITY_CONTEXT),
            PermissionTypes::DELETE
        );

        $contactPerson = $this->manager->getById($id);

        if (!$contactPerson) {
            throw new EntityNotFoundException(ContactPerson::class, $id);
        }

        $this->manager->delete($contactPerson);

        return $this->handleView($this->view(null, 204));
    }

    /** @return array<string, mixed> */
    private function serialize(ContactPerson $contactPerson): array
    {
        return [
            'id' => $contactPerson->getId(),
            'firstName' => $contactPerson->getFirstName(),
            'lastName' => $contactPerson->getLastName(),
            'fullName' => $contactPerson->getFullName(),
            'position' => $contactPerson->getPosition(),
            'email' => $contactPerson->getEmail(),
            'phone' => $contactPerson->getPhone(),
            'mediaId' => $contactPerson->getMediaId() !== null ? ['id' => $contactPerson->getMediaId()] : null,
        ];
    }

    /** @return array{code: int, message: string, violations: list<array{property: string, message: string}>} */
    private function formatViolations(ValidationFailedException $e): array
    {
        $violations = [];
        foreach ($e->getViolations() as $violation) {
            $violations[] = [
                'property' => (string) $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return [
            'code' => 422,
            'message' => 'Validation failed.',
            'violations' => $violations,
        ];
    }
}
