<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $statusFilter = (string) $request->query('status', '');

        $deliveries = Delivery::query()
            ->with('store:id,name')
            ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
            ->latest('last_update_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $ordersQuery = Order::query();
        $todayOrdersQuery = Order::query()->whereDate('created_at', today());

        $stats = [
            'total_deliveries' => Delivery::count(),
            'pending_deliveries' => Delivery::whereIn('status', ['new', 'pending'])->count(),
            'completed_deliveries' => Delivery::where('status', 'delivered')->count(),
            'webhook_events' => WebhookEvent::count(),
            'total_stores' => Store::count(),
            'active_products' => Product::query()->where('is_active', true)->count(),
            'total_orders' => (clone $ordersQuery)->count(),
            'today_orders' => (clone $todayOrdersQuery)->count(),
            'total_revenue' => (float) (clone $ordersQuery)->where('payment_status', 'paid')->sum('total'),
            'today_revenue' => (float) (clone $todayOrdersQuery)->where('payment_status', 'paid')->sum('total'),
            'pending_receivables' => (float) Order::query()
                ->whereIn('payment_status', ['pending', 'authorized'])
                ->sum('total'),
        ];

        $stats['avg_ticket'] = $stats['total_orders'] > 0
            ? round($stats['total_revenue'] / $stats['total_orders'], 2)
            : 0;

        $statusOptions = Delivery::query()
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $recentOrders = Order::query()
            ->with('store:id,name')
            ->latest('ordered_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('admin.dashboard', compact('deliveries', 'stats', 'statusOptions', 'statusFilter', 'recentOrders'));
    }

    public function flowPreview(): View
    {
        $slides = [
            [
                'label' => 'Boas-vindas',
                'title' => 'Entrada no bot do WhatsApp',
                'description' => 'Mensagem inicial com opcoes rapidas para acelerar o atendimento.',
                'incoming' => 'Oi! Quero pedir agora.',
                'outgoing' => 'Show! Escolha uma opcao: Cardapio, Promocoes ou Falar com atendente.',
                'categories' => ['Cardapio', 'Promocoes', 'Atendente'],
                'carousel' => ['Menu principal', 'Fluxo por botoes', 'Sem friccao'],
            ],
            [
                'label' => 'Categorias',
                'title' => 'Lista de categorias no WhatsApp',
                'description' => 'Usuario escolhe a secao do cardapio usando lista ou botoes.',
                'incoming' => 'Quero ver o cardapio.',
                'outgoing' => 'Perfeito. Escolha a categoria: Burgers, Pizzas, Bebidas ou Sobremesas.',
                'categories' => ['Burgers', 'Pizzas', 'Bebidas', 'Sobremesas'],
                'carousel' => ['Categoria selecionada', 'Itens filtrados', 'Resposta instantanea'],
            ],
            [
                'label' => 'Carrossel',
                'title' => 'Produtos em carrossel',
                'description' => 'Cards com foto, preco e CTA para adicionar no carrinho.',
                'incoming' => 'Me mostra os mais pedidos de burger.',
                'outgoing' => 'Aqui vao os destaques. Toque em Adicionar para montar seu pedido.',
                'categories' => ['Mais pedidos', 'Combo do dia', 'Vegetariano'],
                'carousel' => ['Smash Duplo - R$ 29,90', 'Chicken Crispy - R$ 27,90', 'Batata Cheddar - R$ 16,90'],
            ],
            [
                'label' => 'Checkout',
                'title' => 'Fechamento e status do pedido',
                'description' => 'Confirmacao de endereco, pagamento e atualizacoes de entrega.',
                'incoming' => 'Fechar pedido com PIX para Rua das Flores, 120.',
                'outgoing' => 'Pedido #4821 confirmado. Em preparo. Aviso voce quando sair para entrega.',
                'categories' => ['Endereco', 'Pagamento', 'Confirmacao'],
                'carousel' => ['Pedido confirmado', 'Em preparo', 'Saiu para entrega'],
            ],
        ];

        return view('admin.flow-preview', compact('slides'));
    }
}
