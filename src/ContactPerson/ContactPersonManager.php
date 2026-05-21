<?php

declare(strict_types=1);

namespace App\ContactPerson;

use App\Entity\ContactPerson;
use App\Repository\ContactPersonRepositoryInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ContactPersonManager implements ContactPersonManagerInterface
{
    public function __construct(
        private readonly ContactPersonRepositoryInterface $repository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function getById(int $id): ?ContactPerson
    {
        return $this->repository->findById($id);
    }

    public function getAll(): array
    {
        return $this->repository->findAll();
    }

    public function create(array $data): ContactPerson
    {
        $contactPerson = $this->repository->create();
        $this->hydrateData($contactPerson, $data);
        $this->assertValid($contactPerson);
        $this->repository->save($contactPerson);

        return $contactPerson;
    }

    public function update(ContactPerson $contactPerson, array $data): ContactPerson
    {
        $this->hydrateData($contactPerson, $data);
        $this->assertValid($contactPerson);
        $this->repository->save($contactPerson);

        return $contactPerson;
    }

    public function delete(ContactPerson $contactPerson): void
    {
        $this->repository->remove($contactPerson);
    }

    /** @param array<string, mixed> $data */
    private function hydrateData(ContactPerson $contactPerson, array $data): void
    {
        $contactPerson->setFirstName($data['firstName'] ?? '');
        $contactPerson->setLastName($data['lastName'] ?? '');
        $contactPerson->setPosition($data['position'] ?? null);
        $contactPerson->setEmail($data['email'] ?? null);
        $contactPerson->setPhone($data['phone'] ?? null);

        $rawMedia = $data['mediaId'] ?? null;
        if (\is_array($rawMedia) && isset($rawMedia['id']) && \is_numeric($rawMedia['id'])) {
            $mediaId = (int) $rawMedia['id'];
        } elseif (\is_numeric($rawMedia)) {
            $mediaId = (int) $rawMedia;
        } else {
            $mediaId = null;
        }
        $contactPerson->setMediaId($mediaId);
    }

    private function assertValid(ContactPerson $contactPerson): void
    {
        $violations = $this->validator->validate($contactPerson);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($contactPerson, $violations);
        }
    }
}
