<?php

namespace App\Admin\Controllers;

use App\Shipping\Infrastructure\Shipments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Carrier shipments of one order: create, print the label, refresh tracking, cancel, release an interrupted creation. */
final class OrderShipmentController
{
    public function store(Request $request, Shipments $shipments, string $order): RedirectResponse
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'fields' => ['nullable', 'array'], 'additional' => ['sometimes', 'boolean']]);
        $shipments->create((string) $request->user()->id, $order, $data['request_key'], $data['fields'] ?? [], (bool) ($data['additional'] ?? false));

        return redirect('/admin/orders/'.$order)->with('status', __('shipping.shipment_created'));
    }

    public function label(Request $request, Shipments $shipments, string $order, string $shipment): HttpResponse
    {
        $this->owned($order, $shipment);
        $data = $request->validate(['format' => ['nullable', 'string', 'max:20']]);

        return ShippingCarrierController::pdf($shipments->label((string) $request->user()->id, $shipment, $data['format'] ?? 'laser'));
    }

    public function track(Request $request, Shipments $shipments, string $order, string $shipment): RedirectResponse
    {
        $this->owned($order, $shipment);
        $result = $shipments->track((string) $request->user()->id, $shipment);

        return redirect('/admin/orders/'.$order)->with('status', __('shipping.tracking_updated', ['state' => __('shipping.tracking_'.$result->state)]));
    }

    public function cancel(Request $request, Shipments $shipments, string $order, string $shipment): RedirectResponse
    {
        $this->owned($order, $shipment);
        $shipments->cancel((string) $request->user()->id, $shipment);

        return redirect('/admin/orders/'.$order)->with('status', __('shipping.shipment_cancelled'));
    }

    public function resolve(Request $request, Shipments $shipments, string $order, string $shipment): RedirectResponse
    {
        $this->owned($order, $shipment);
        $shipments->markFailed((string) $request->user()->id, $shipment);

        return redirect('/admin/orders/'.$order)->with('status', __('shipping.shipment_released'));
    }

    /** A shipment is only reachable through the order it belongs to. */
    private function owned(string $order, string $shipment): void
    {
        abort_unless(DB::table('order_shipments')->where('id', $shipment)->where('order_id', $order)->exists(), 404);
    }
}
