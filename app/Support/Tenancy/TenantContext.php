<?php

namespace App\Support\Tenancy;

class TenantContext
{
    public function __construct(private ?int $companyId = null)
    {
    }

    public function setCompanyId(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function hasCompany(): bool
    {
        return $this->companyId !== null;
    }
}
