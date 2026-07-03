/**
 * Scheme Staff — form intake for Google Sheets.
 *
 * This script lives INSIDE a Google Sheet (Extensions → Apps Script) and is
 * deployed as a web app. The website's forms POST JSON here; each submission
 * becomes a row on a tab named after the form ("Candidates", "Employers",
 * "Job postings"), and uploaded documents are saved to a Drive folder called
 * "SchemeStaff Uploads", with links placed in the row.
 *
 * Deploy: Deploy → New deployment → Web app → Execute as: Me →
 * Who has access: Anyone → copy the /exec URL into SUBMIT_URL in script.js.
 */

const UPLOAD_FOLDER = 'SchemeStaff Uploads';

function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);
    if (!data || !data.formType || !data.fields) throw new Error('Unrecognised payload');

    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName(data.formType) || ss.insertSheet(data.formType);

    const row = { 'Submitted at': new Date() };
    Object.keys(data.fields).forEach(key => { row[key] = data.fields[key]; });

    (data.files || []).forEach(f => {
      const blob = Utilities.newBlob(
        Utilities.base64Decode(f.base64),
        f.mimeType || 'application/octet-stream',
        f.filename
      );
      const url = uploadFolder_(data.formType).createFile(blob).getUrl();
      row[f.field] = row[f.field] ? row[f.field] + '\n' + url : url;
    });

    // Keep the header row in sync with whatever keys arrive
    const lastCol = sheet.getLastColumn();
    const headers = lastCol ? sheet.getRange(1, 1, 1, lastCol).getValues()[0] : ['Submitted at'];
    Object.keys(row).forEach(key => {
      if (headers.indexOf(key) === -1) headers.push(key);
    });
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    sheet.appendRow(headers.map(h => (row[h] !== undefined ? row[h] : '')));

    return json_({ ok: true });
  } catch (err) {
    return json_({ ok: false, error: String(err) });
  }
}

function uploadFolder_(subfolderName) {
  const root = childFolder_(DriveApp.getRootFolder(), UPLOAD_FOLDER);
  return childFolder_(root, subfolderName);
}

function childFolder_(parent, name) {
  const existing = parent.getFoldersByName(name);
  return existing.hasNext() ? existing.next() : parent.createFolder(name);
}

function json_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
