<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;

class TestStatus extends Command
{
    // O comando que você vai digitar no terminal
    protected $signature = 'zap:test {id=76}';

    protected $description = 'Atalho rápido para alternar status do pedido no Zapediu';

    public function handle()
    {
        $id = 76;
        $order = Order::find($id);

        if (!$order) {
            $this->error("❌ Pedido {$id} não encontrado no banco MySQL.");
            return Command::FAILURE;
        }

        // 1. Extrai a string real, independentemente de ser Enum ou não
        $oldStatusValue = is_object($order->status) ? $order->status->value : $order->status;

        // 2. Faz a comparação com a string extraída
        $newStatusValue = ($oldStatusValue === 'pending') ? 'preparToDelivery' : 'pending';

        // 3. Atualiza (O Laravel converte a string de volta para Enum automaticamente)
        $order->update(['status' => $newStatusValue]);

        $this->info("✅ Sucesso!");
        $this->line("Pedido: #{$id}");
        $this->line("Status anterior: <fg=red>{$oldStatusValue}</>");
        $this->line("Status atual: <fg=green>{$newStatusValue}</>");

        return Command::SUCCESS;
    }
}
