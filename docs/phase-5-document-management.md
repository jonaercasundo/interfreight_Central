# Phase 5 Document Management

Shipment documents are stored on the private `local` disk under shipment-specific paths. The application serves files only through an authenticated download route that checks document permission and shipment visibility. Uploads are limited to supported office/PDF/image extensions and 20 MiB.

Each upload has a category, original filename, MIME type, byte size, uploader, optional expiry, verification state and version number. Replacements append a new version and retain the prior record. Upload idempotency keys prevent retry requests from creating duplicate version records.

POD uploads must reference a delivery belonging to the same shipment. POD verification changes document and delivery review states without closing the master shipment. Permission checks distinguish general document upload/view, Operations verification, and Transport POD upload/verification.

The current implementation provides document categories and review states. It does not yet provide a configurable per-service required-document checklist or retention automation.
