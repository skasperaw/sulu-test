<?php

declare(strict_types=1);

namespace App\ContactPerson;

use App\Entity\ContactPerson;

interface ContactPersonManagerInterface
{
    public function getById(int $id): ?ContactPerson;

    /** @return ContactPerson[] */
    public function getAll(): array;

    /** @param array<string, mixed> $data */
    public function create(array $data): ContactPerson;

    /** @param array<string, mixed> $data */
    public function update(ContactPerson $contactPerson, array $data): ContactPerson;

    public function delete(ContactPerson $contactPerson): void;
}
