/**
 * A stock change announced by the server on the "stock" broadcast channel.
 *
 * Mirrors ProductStockUpdatedEvent::broadcastWith(). Only what a till needs to
 * patch its cached product list — no prices, no supplier data.
 */
export interface StockUpdate {
    product_id: number;
    unit_quantity_id: number;
    unit_id: number;
    quantity: number;
}
