# VillonFarm POS - Features & Functions

A Point-of-Sale and farm management system designed for insecticide/agrochemical retail businesses in the Kenyan agricultural market.

---

## Authentication

- Email/password login with "remember me" support
- Session-based auth protecting all routes
- Branded login page

## Dashboard

- **KPI Cards** — Revenue today, total revenue, products in stock, sales recorded
- **Revenue Chart** — Last 7 days revenue displayed as a server-side SVG polyline chart
- **Attention Panel** — Flags low-stock products (<= 10 units) and products expiring within 6 months
- **Recent Sales** — Last 3 transactions with customer name, timestamp, item count, and total
- **Farm Count** — Total number of farmers/customers on file

## Point of Sale (POS)

- **Product Grid** — Searchable product listing by name and active ingredient
- **Tap-to-Add Cart** — Click a product to add it to the current sale
- **Cart Management** — Increment/decrement quantity, remove items, respecting available stock
- **Customer Selection** — Optional walk-in customer support
- **Discount Field** — Manual numeric discount on the sale total
- **Payment Methods** — Cash, Card, Mobile Money
- **Stock Enforcement** — Prevents overselling with validation against available stock
- **Atomic Transactions** — Uses `DB::transaction()` with `lockForUpdate()` to prevent race conditions
- **Automatic Stock Decrement** — Stock is decremented per line item upon sale completion

## Inventory Management

- **Full CRUD** — Create, read, update, and delete products
- **Product Fields** — Name, active ingredient, batch number, expiry date, price, stock quantity, unit
- **Searchable Table** — Filter products by name or ingredient
- **Inline Edit Modals** — Edit product details without leaving the page
- **Visual Flags** — Low stock highlighted in red, expiring-soon badge on products nearing expiry
- **Delete Confirmation** — Prompt before permanent deletion

## Customer Management

- **Full CRUD** — Create, read, update, and delete customer records
- **Customer Fields** — Name, farm name, phone, location, crop type, hectares, notes
- **Card-Based Layout** — Customers displayed as cards with farm details
- **Searchable** — Filter customers via header search box
- **Lifetime Spend** — Calculated from sum of all customer sales
- **Inline Edit Modals** — Edit customer details in-place
- **Delete Confirmation** — Prompt before permanent deletion

## Sales History

- **Summary KPIs** — Total revenue, items sold, average sale value
- **Transaction Table** — Date, customer, item count, payment method badge, total
- **Top Products Sidebar** — Ranked by revenue with units sold and revenue per product
- **Catalog Count** — Total products currently in inventory

## Reports & Export

| Report     | PDF | CSV | Date Filter |
|------------|-----|-----|-------------|
| Sales      | Yes | Yes | From / To   |
| Inventory  | Yes | Yes | —           |
| Customers  | Yes | Yes | —           |

- **PDF Reports** — Branded with VillonFarm POS letterhead, styled tables, and summary rows
- **CSV Exports** — Streamed downloads for spreadsheet use
- **Sales Report** — Summary stats, transaction detail table, and top-products ranking

## Data Model

### Entities

| Entity    | Key Fields                                                        |
|-----------|-------------------------------------------------------------------|
| Product   | name, active_ingredient, batch_number, expiry_date, price, stock, unit |
| Customer  | name, farm_name, phone, location, crop, hectares, notes           |
| Sale      | customer_id, subtotal, discount, total, payment_method            |
| SaleItem  | sale_id, product_id, quantity, unit_price, line_total             |

### Relationships

- Customer → has many Sales
- Sale → belongs to Customer, has many SaleItems
- SaleItem → belongs to Sale and Product

## Technical Highlights

- **Decimal Precision** — All monetary and quantity fields use `decimal(10,2)` supporting fractional quantities (e.g. 2.5 liters)
- **Server-Side SVG Charting** — Dashboard chart rendered as pure SVG with no JS charting library
- **Responsive Design** — Mobile-friendly sidebar with Alpine.js hamburger menu, Tailwind responsive breakpoints
- **Blade + Alpine.js** — Custom admin panel without Filament or Livewire for full UI control
- **PDF Branded Reports** — Consistent letterhead across all exported documents

## Test Coverage

| Test File                | Tests | Coverage                                              |
|--------------------------|-------|-------------------------------------------------------|
| PosSaleTest              | 3     | Sale completion, stock decrement, overflow, empty cart |
| DashboardTest            | 2     | KPI totals, low stock / expiring flags                |
| InventoryManagementTest  | 6     | Full CRUD, low-stock flag, expiring-soon flag         |
| CustomerManagementTest   | 5     | Full CRUD operations                                  |
| ReportsTest              | 5     | All report types (PDF & CSV), date filtering           |

## Demo Data

- **6 insecticide products** — Real-world agrochemical names and pricing
- **3 farmers** — Kenyan context with farm details, crops, and locations
- **3 historical sales** — Staggered timestamps for dashboard chart rendering
