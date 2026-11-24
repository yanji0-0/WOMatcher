# Work Order Matcher — Frontend Demo

## Run

From this folder, start a local static server:

```powershell
python -m http.server 8000
```

Open `http://127.0.0.1:8000/`.

## Test the demo

1. Upload one PDF at a time in each PDF category using browse or drag-and-drop. Files are simulated only; PDF contents are not read.
2. Open **Extracted Records** to search, filter, sort, expand descriptions, and clear the view.
3. Choose **Run Matching** in the Workspace to start the simulated matching process.
4. Open **Matching Results** to filter statuses, select rows, review a pair, confirm/reject matches, or create a manual match.
5. Use **Export Results** to download a mock CSV containing only confirmed 7-digit Sequence and 9-digit ELB WO pairs.
6. Test **Reset Matching** and **New Session** from their confirmation dialogs.

## Phase 2 integration points

- Replace `handleFiles()` with multipart upload endpoints and real file metadata from the PHP API.
- Replace `extractRecords()` with records returned by Smalot PDF Parser.
- Replace `mockMatches` and `rerun` with server-side matching data and matching-job status endpoints.
- Replace the CSV Blob implementation with a server-generated XLSX download if required.
- Replace local session state with PHP/MySQL session persistence only after frontend approval.
