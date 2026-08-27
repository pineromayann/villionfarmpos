# VillonFarm POS - Way Forward

A roadmap of improvements and new features planned for the system.

---

## High Priority

- [ ] **Role-Based Access Control** — Add user roles (admin, cashier, manager) with permission-based route and action gating
- [ ] **Stock Adjustment / Audit Trail** — Allow manual stock corrections with a reason log and history tracking
- [ ] **Returns & Refunds** — Support processing returns, reversing stock, and recording refund transactions
- [ ] **Product Categories** — Group products by category (insecticide, herbicide, fungicide, etc.) for better organization and filtering
- [ ] **Print Receipts** — Generate printable receipts for completed sales

## Medium Priority

- [ ] **Customer Purchase History** — Dedicated page showing a customer's full transaction history with totals over time
- [ ] **Reorder Alerts** — Automatic low-stock notifications with configurable thresholds per product
- [ ] **Batch & Expiry Tracking Reports** — Dedicated view for products grouped by batch number with expiry countdown
- [ ] **Sales by Period Analytics** — Daily, weekly, monthly, and yearly sales breakdowns with trend charts
- [ ] **User Activity Log** — Track who created, updated, or deleted records across the system
- [ ] **Multi-User Support** — Track which cashier processed each sale
- [ ] **Payment Tracking** — Record partial payments and outstanding balances for credit sales

## Low Priority

- [ ] **Product Image Upload** — Allow attaching images to product records
- [ ] **Customer Communication Log** — Record notes from phone calls or visits with timestamps
- [ ] **Crop-Based Recommendations** — Suggest products based on customer's crop type and common pest issues
- [ ] **Seasonal Sales Trends** — Visualize sales patterns across planting and harvesting seasons
- [ ] **SMS Integration** — Send sale confirmations or low-stock alerts via SMS (e.g. Africa's Talking API)
- [ ] **Barcode / QR Support** — Generate and scan barcodes for products at the POS
- [ ] **Multi-Branch Support** — Manage multiple shop locations with independent or shared inventory
- [ ] **Data Backup & Export** — Scheduled database backups with download capability

## Technical Improvements

- [ ] **API Layer** — Build a REST or GraphQL API for mobile app or third-party integrations
- [ ] **Caching** — Add Redis/file caching for dashboard KPIs and frequently accessed queries
- [ ] **Queue Jobs** — Move PDF generation and CSV exports to queued jobs for large datasets
- [ ] **Livewire Upgrade** — Evaluate migrating interactive components from Alpine.js to Livewire for richer server-driven UI
- [ ] **CI/CD Pipeline** — Automated testing and deployment via GitHub Actions or similar
- [ ] **Laravel Scout Search** — Full-text search for products and customers using Scout with a database driver
- [ ] **Observability** — Add structured logging, error tracking (e.g. Sentry), and performance monitoring
