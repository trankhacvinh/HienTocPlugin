const MYHAIR_SECRET = 'CHANGE_THIS_TO_THE_SAME_SECRET_IN_WORDPRESS';

function doPost(e) {
  try {
    const body = JSON.parse((e && e.postData && e.postData.contents) || '{}');
    if (!body.secret || body.secret !== MYHAIR_SECRET) {
      return jsonResponse({ ok: false, error: 'Invalid secret' });
    }

    if (body.action === 'ping') {
      return jsonResponse({ ok: true, message: 'MyHair Google Sheets endpoint is ready' });
    }

    if (body.action !== 'upsert_submission' || !body.submission) {
      return jsonResponse({ ok: false, error: 'Unsupported action' });
    }

    const s = body.submission;
    const tabName = s.form_key === 'member'
      ? ((body.sheet_tabs && body.sheet_tabs.member) || 'Thanh vien')
      : ((body.sheet_tabs && body.sheet_tabs.donation) || 'Hien toc');

    const sheet = getOrCreateSheet(tabName);
    const rowObject = flattenSubmission(body);
    upsertBySubmissionCode(sheet, rowObject);

    return jsonResponse({ ok: true, sheet: tabName, submission_code: s.submission_code });
  } catch (err) {
    return jsonResponse({ ok: false, error: String(err && err.message ? err.message : err) });
  }
}

function getOrCreateSheet(name) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(name);
  if (!sheet) sheet = ss.insertSheet(name);
  return sheet;
}

function flattenSubmission(body) {
  const s = body.submission || {};
  const row = {
    synced_at: new Date().toISOString(),
    event: body.event || '',
    submission_code: s.submission_code || '',
    form_key: s.form_key || '',
    form_name: s.form_name || '',
    status: s.status || '',
    status_label: s.status_label || '',
    salon_code: s.salon_code || '',
    salon_name: s.salon_name || '',
    salon_owner: s.salon_owner || '',
    salon_owner_email: s.salon_owner_email || '',
    full_name: s.full_name || '',
    phone: s.phone || '',
    email: s.email || '',
    date_of_birth: s.date_of_birth || '',
    created_at: s.created_at || '',
    updated_at: s.updated_at || '',
    source_url: s.source_url || '',
    public_id: s.public_id || '',
    submission_id: s.id || ''
  };

  const fields = s.fields || {};
  Object.keys(fields).forEach(function (key) {
    if (typeof row[key] === 'undefined') row[key] = fields[key];
    else row['field_' + key] = fields[key];
  });
  return row;
}

function upsertBySubmissionCode(sheet, rowObject) {
  let headers = [];
  const lastColumn = sheet.getLastColumn();
  if (sheet.getLastRow() > 0 && lastColumn > 0) {
    headers = sheet.getRange(1, 1, 1, lastColumn).getValues()[0].map(String);
  }

  const keys = Object.keys(rowObject);
  let changed = false;
  keys.forEach(function (key) {
    if (headers.indexOf(key) === -1) {
      headers.push(key);
      changed = true;
    }
  });

  if (headers.length === 0) headers = keys;
  if (sheet.getLastRow() === 0 || changed) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    sheet.setFrozenRows(1);
  }

  const codeColumn = headers.indexOf('submission_code') + 1;
  let targetRow = 0;
  if (codeColumn > 0 && sheet.getLastRow() >= 2) {
    const values = sheet.getRange(2, codeColumn, sheet.getLastRow() - 1, 1).getValues();
    for (let i = 0; i < values.length; i++) {
      if (String(values[i][0]) === String(rowObject.submission_code)) {
        targetRow = i + 2;
        break;
      }
    }
  }

  const row = headers.map(function (header) {
    return typeof rowObject[header] === 'undefined' ? '' : rowObject[header];
  });

  if (targetRow > 0) {
    sheet.getRange(targetRow, 1, 1, headers.length).setValues([row]);
  } else {
    sheet.appendRow(row);
  }
}

function jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
