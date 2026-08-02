<?php

namespace App\Support\Tenancy;

class TenantContext
{
    /** @var array<int, int>|null */
    private ?array $companyIds = null;

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
     * Conjunto de companies de uma mesma Operation (várias lojas, um número de WhatsApp só).
     * Setado pelo webhook do FlowBridge quando a instância resolvida pertence a uma Operation
     * com mais de uma company — CompanyScope usa isso (whereIn) em vez do companyId único.
     *
     * @param  array<int, int>|null  $companyIds
     */
    public function setCompanyIds(?array $companyIds): void
    {
        $this->companyIds = $companyIds !== [] ? $companyIds : null;
    }

    /** @return array<int, int>|null */
    public function companyIds(): ?array
    {
        return $this->companyIds;
    }

    public function hasCompanyIds(): bool
    {
        return $this->companyIds !== null;
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
