<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactPerson;

interface ContactPersonRepositoryInterface
{
    public function findById(int $id): ?ContactPerson;

    /** @return ContactPerson[] */
    public function findAll(): array;

    public function create(): ContactPerson;

    public function save(ContactPerson $contactPerson): void;

    public function remove(ContactPerson $contactPerson): void;
}
