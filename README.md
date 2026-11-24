# Work Order Matcher

## Run

Install PHP dependencies and start the PHP development server:

```powershell
composer install
php -S 127.0.0.1:8001
```

Open `http://127.0.0.1:8001/`.

## Test the demo

1. Upload one text-based PDF or the source XLSX containing the complete W/O table.
2. Review extracted W/O, A/C, Date, ATA, and Description fields.
3. Choose **Run Matching** to apply reference, date, A/C, ATA, and fuzzy-description rules.
4. Review uncertain pairs in **Needs Review**, then confirm or reject them.
5. Use **Export Excel** to download confirmed identifier pairs only.
6. Test **Reset Matching**, **Delete PDF**, and **New Session** from their confirmation dialogs.

## API

- `POST api.php?action=extract` accepts one PDF or XLSX. PDFs use Smalot PDF Parser; XLSX files use PhpSpreadsheet.
- `POST api.php?action=match` accepts extracted rows and returns rule-based matches.
- Extraction diagnostics include file type, character count, identifier count, parsed/rejected rows, and rejection samples.
- PDF parsing uses positioned text fragments and discovers the W/O, A/C, Date, ATA, and Description columns from each page header. Rows with missing or ambiguous fields are returned as `valid: false` for review instead of being silently discarded.
- Development-only PDF diagnostics can be enabled by setting `WOM_DEV_DIAGNOSTICS=true` before starting PHP. The extraction response then includes the first 50 extracted lines per page and a redacted identifier context. This is off by default and document text is never written to production logs.
- Scanned PDFs return an explicit no-text error; OCR is not silently assumed.
