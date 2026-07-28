<?php

namespace Database\Seeders;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\SoldBy;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\Concerns\SeedsPublicIds;
use Illuminate\Database\Seeder;

/**
 * The 10 categories and 32 products from `db/seed.sql`, with a primary image, an opening
 * stock level and a matching ledger entry for each.
 *
 * The mix is deliberate: 15 of the 32 are priced per kilogram, so every read path is
 * exercised against both pricing shapes rather than the unit-priced happy case alone.
 */
class CatalogSeeder extends Seeder
{
    use SeedsPublicIds;

    public function run(): void
    {
        $this->seedCategories();
        $this->seedProducts();
        $this->seedImagesAndInventory();
    }

    private function seedCategories(): void
    {
        $categories = [
            [1, null, 'Produce', 'produce', 'Fresh fruit and vegetables', 10],
            [2, 1, 'Fruit', 'fruit', null, 10],
            [3, 1, 'Vegetables', 'vegetables', null, 20],
            [4, null, 'Dairy & Eggs', 'dairy-eggs', 'Milk, cheese, yoghurt and eggs', 20],
            [5, null, 'Bakery', 'bakery', 'Baked fresh daily', 30],
            [6, null, 'Meat & Seafood', 'meat-seafood', 'Butcher and fish counter', 40],
            [7, 6, 'Poultry', 'poultry', null, 10],
            [8, 6, 'Seafood', 'seafood', null, 20],
            [9, null, 'Pantry', 'pantry', 'Dry goods and staples', 50],
            [10, null, 'Drinks', 'drinks', null, 60],
        ];

        foreach ($categories as [$id, $parentId, $name, $slug, $description, $position]) {
            (new Category)->forceFill([
                'id' => $id,
                'public_id' => $this->publicId(3, $id),
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'position' => $position,
                'is_active' => true,
            ])->save();
        }
    }

    private function seedProducts(): void
    {
        foreach ($this->products() as $row) {
            [$id, $categoryId, $sku, $name, $slug, $brand, $description, $soldBy, $unitLabel,
                $price, $pricePerKg, $compareAt, $averageWeightKg, $minQty, $maxQty] = $row;

            (new Product)->forceFill([
                'id' => $id,
                'public_id' => $this->publicId(4, $id),
                'category_id' => $categoryId,
                'sku' => $sku,
                'name' => $name,
                'slug' => $slug,
                'brand' => $brand,
                'description' => $description,
                'sold_by' => $soldBy,
                'unit_label' => $unitLabel,
                'price' => $price,
                'price_per_kg' => $pricePerKg,
                'compare_at_price' => $compareAt,
                'average_weight_kg' => $averageWeightKg,
                'weight_tolerance_pct' => 10.00,
                'min_order_quantity' => $minQty,
                'max_order_quantity' => $maxQty,
                'is_active' => true,
            ])->save();
        }
    }

    private function seedImagesAndInventory(): void
    {
        foreach (Product::all() as $product) {
            ProductImage::create([
                'product_id' => $product->id,
                'url' => "https://cdn.grocerly.example/products/{$product->slug}.jpg",
                'alt_text' => $product->name,
                'position' => 0,
                'is_primary' => true,
            ]);

            $isWeighed = $product->sold_by->isWeighed();

            $onHand = match (true) {
                $product->id % 13 === 0 => '0.000',      // out of stock
                $product->id % 7 === 0 => '3.000',       // low
                $isWeighed => '45.000',
                default => '120.000',
            };

            Inventory::create([
                'product_id' => $product->id,
                'quantity_on_hand' => $onHand,
                'quantity_reserved' => '0.000',
                'low_stock_threshold' => $isWeighed ? '5.000' : '12.000',
            ]);

            // ck_inv_adj_nonzero rejects a zero delta, so out-of-stock lines get no opening
            // ledger entry — there was no movement to record.
            if ($onHand !== '0.000') {
                InventoryAdjustment::create([
                    'product_id' => $product->id,
                    'delta' => $onHand,
                    'reason' => InventoryAdjustmentReason::Restock,
                    'note' => 'Opening stock count',
                    'created_by' => 3,
                ]);
            }
        }
    }

    /**
     * @return array<int, array{int, int, string, string, string, string, string, SoldBy, string, float|null, float|null, float|null, float|null, float, float}>
     */
    private function products(): array
    {
        $unit = SoldBy::Unit;
        $weight = SoldBy::Weight;

        return [
            [1, 2, 'PRD-BAN-001', 'Bananas, Loose', 'bananas-loose', 'Farm Fresh', 'Sweet Cavendish bananas, sold loose by weight.', $weight, 'kg', null, 1.52, null, 1.000, 0.250, 15.000],
            [2, 2, 'PRD-APP-002', 'Gala Apples', 'gala-apples', 'Orchard Lane', 'Crisp and mildly sweet. Great for lunchboxes.', $weight, 'kg', null, 3.28, 3.90, 1.000, 0.250, 10.000],
            [3, 2, 'PRD-ORA-003', 'Navel Oranges', 'navel-oranges', 'Orchard Lane', 'Seedless, easy-peel navel oranges.', $weight, 'kg', null, 2.85, null, 1.000, 0.500, 10.000],
            [4, 2, 'PRD-STR-004', 'Strawberries 454g', 'strawberries-454g', 'Berry Best', 'One-pound clamshell of ripe strawberries.', $unit, 'ea', 4.99, null, 6.49, null, 1.000, 12.000],
            [5, 2, 'PRD-AVO-005', 'Hass Avocado', 'hass-avocado', 'Farm Fresh', 'Ripe-and-ready Hass avocado, sold each.', $unit, 'ea', 1.79, null, null, null, 1.000, 20.000],
            [6, 2, 'PRD-GRP-006', 'Seedless Green Grapes', 'seedless-green-grapes', 'Farm Fresh', 'Crunchy seedless grapes, sold by weight.', $weight, 'kg', null, 5.49, null, 1.000, 0.250, 6.000],
            [7, 3, 'PRD-TOM-007', 'Vine Tomatoes', 'vine-tomatoes', 'Farm Fresh', 'Tomatoes on the vine, ripened for flavour.', $weight, 'kg', null, 4.40, null, 1.000, 0.250, 8.000],
            [8, 3, 'PRD-POT-008', 'Russet Potatoes', 'russet-potatoes', 'Farm Fresh', 'All-purpose baking potatoes.', $weight, 'kg', null, 1.98, null, 1.000, 0.500, 20.000],
            [9, 3, 'PRD-CAR-009', 'Carrots', 'carrots', 'Farm Fresh', 'Sweet snacking carrots, sold by weight.', $weight, 'kg', null, 1.74, null, 1.000, 0.250, 10.000],
            [10, 3, 'PRD-ONI-010', 'Yellow Onions', 'yellow-onions', 'Farm Fresh', 'Everyday cooking onions.', $weight, 'kg', null, 1.65, null, 1.000, 0.250, 10.000],
            [11, 3, 'PRD-SPI-011', 'Baby Spinach 142g', 'baby-spinach-142g', 'Green Leaf', 'Triple-washed baby spinach, ready to eat.', $unit, 'ea', 3.49, null, null, null, 1.000, 10.000],
            [12, 3, 'PRD-BRO-012', 'Broccoli Crown', 'broccoli-crown', 'Farm Fresh', 'Tight dark-green crowns.', $weight, 'kg', null, 3.95, null, 1.000, 0.250, 6.000],
            [13, 4, 'PRD-MLK-013', 'Whole Milk, 1 Gallon', 'whole-milk-1-gal', 'Meadow Farms', 'Grade A pasteurised whole milk.', $unit, 'ea', 4.29, null, null, null, 1.000, 6.000],
            [14, 4, 'PRD-MLK-014', 'Oat Milk, 1L', 'oat-milk-1l', 'Oatly Day', 'Barista-style oat drink.', $unit, 'ea', 3.99, null, 4.79, null, 1.000, 12.000],
            [15, 4, 'PRD-EGG-015', 'Large Eggs, Dozen', 'large-eggs-dozen', 'Happy Hen', 'Cage-free large brown eggs.', $unit, 'ea', 5.49, null, null, null, 1.000, 6.000],
            [16, 4, 'PRD-BUT-016', 'Unsalted Butter 227g', 'unsalted-butter-227g', 'Meadow Farms', 'Sweet cream unsalted butter.', $unit, 'ea', 4.79, null, null, null, 1.000, 10.000],
            [17, 4, 'PRD-CHE-017', 'Sharp Cheddar Block', 'sharp-cheddar-block', 'Vale Dairy', 'Aged 12 months, cut to weight at the counter.', $weight, 'kg', null, 18.70, null, 1.000, 0.150, 2.000],
            [18, 4, 'PRD-YOG-018', 'Greek Yoghurt 500g', 'greek-yoghurt-500g', 'Vale Dairy', 'Thick strained plain yoghurt.', $unit, 'ea', 4.29, null, null, null, 1.000, 10.000],
            [19, 5, 'PRD-BRD-019', 'Sourdough Loaf', 'sourdough-loaf', 'Corner Bake', 'Naturally leavened, baked this morning.', $unit, 'ea', 5.49, null, null, null, 1.000, 5.000],
            [20, 5, 'PRD-BRD-020', 'Wholemeal Sandwich Bread', 'wholemeal-sandwich-bread', 'Corner Bake', 'Soft wholemeal sliced loaf.', $unit, 'ea', 3.29, null, null, null, 1.000, 5.000],
            [21, 5, 'PRD-CRO-021', 'Butter Croissant, 4 Pack', 'butter-croissant-4pk', 'Corner Bake', 'All-butter croissants, par-baked.', $unit, 'ea', 6.49, null, 7.99, null, 1.000, 6.000],
            [22, 5, 'PRD-BAG-022', 'Everything Bagels, 6 Pack', 'everything-bagels-6pk', 'Corner Bake', 'Hand-rolled and boiled.', $unit, 'ea', 4.99, null, null, null, 1.000, 6.000],
            [23, 7, 'PRD-CHK-023', 'Chicken Breast Fillets', 'chicken-breast-fillets', 'Blue Barn', 'Boneless skinless breast, tray-packed. Priced per kg.', $weight, 'ea', null, 9.90, null, 0.800, 1.000, 6.000],
            [24, 7, 'PRD-CHK-024', 'Whole Chicken', 'whole-chicken', 'Blue Barn', 'Free-range whole bird. Priced per kg.', $weight, 'ea', null, 6.60, null, 1.800, 1.000, 4.000],
            [25, 6, 'PRD-BEF-025', 'Beef Mince 20% Fat', 'beef-mince-20', 'Blue Barn', 'Coarse-ground chuck. Priced per kg.', $weight, 'kg', null, 12.10, null, 1.000, 0.250, 5.000],
            [26, 8, 'PRD-SAL-026', 'Atlantic Salmon Fillet', 'atlantic-salmon-fillet', 'Harbour Co', 'Skin-on fillet, cut to order. Priced per kg.', $weight, 'ea', null, 27.50, null, 0.220, 1.000, 8.000],
            [27, 8, 'PRD-SHR-027', 'Raw King Prawns 300g', 'raw-king-prawns-300g', 'Harbour Co', 'Peeled and deveined, frozen.', $unit, 'ea', 11.99, null, null, null, 1.000, 6.000],
            [28, 9, 'PRD-RIC-028', 'Basmati Rice 2kg', 'basmati-rice-2kg', 'Golden Field', 'Aged long-grain basmati.', $unit, 'ea', 8.99, null, null, null, 1.000, 4.000],
            [29, 9, 'PRD-PAS-029', 'Spaghetti 500g', 'spaghetti-500g', 'Casa Nostra', 'Bronze-die durum wheat spaghetti.', $unit, 'ea', 2.19, null, null, null, 1.000, 12.000],
            [30, 9, 'PRD-OIL-030', 'Olive Oil 750ml', 'olive-oil-750ml', 'Casa Nostra', 'Cold-pressed extra virgin olive oil.', $unit, 'ea', 13.49, null, 15.99, null, 1.000, 4.000],
            [31, 10, 'PRD-COF-031', 'Ground Coffee 340g', 'ground-coffee-340g', 'Dawn Roast', 'Medium roast, ground for filter.', $unit, 'ea', 9.75, null, null, null, 1.000, 6.000],
            [32, 10, 'PRD-JUI-032', 'Orange Juice 1.75L', 'orange-juice-175l', 'Grove Press', 'Not-from-concentrate, no added sugar.', $unit, 'ea', 5.29, null, null, null, 1.000, 8.000],
        ];
    }
}
