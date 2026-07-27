<?php

namespace App\Support\Tenancy;

class TenantContext
{
    public function __construct(private ?int $companyId = null, private ?string $whatsappInstanceId = null)
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

    /**
     * Instância WhatsApp que recebeu o evento atual — setada direto pelo webhook a partir da
     * WhatsappSession resolvida, sem depender de company/loja. Uma resposta (ex.: saudação
     * inicial) precisa sair pelo mesmo número que recebeu a mensagem, mesmo quando a operação
     * ainda não tem nenhuma loja vinculada.
     */
    public function setWhatsappInstanceId(?string $instanceId): void
    {
        $this->whatsappInstanceId = $instanceId;
    }

    public function whatsappInstanceId(): ?string
    {
        return $this->whatsappInstanceId;
    }
}
