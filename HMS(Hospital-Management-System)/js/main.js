/**
 * HMS — Main JavaScript
 * Vanilla JS utilities; no framework dependency.
 */

'use strict';

// Sidebar toggle (mobile)
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function (e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
    }
});

// Alert auto-dismiss (after 10s)
(function autoCloseAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 420);
        }, 10000);
    });
}());

// Client-side form validation helper 
/**
 * Validate a <form> element. Marks fields with .isInvalid and shows .field-error text.
 * Returns true only if all required fields are non-empty and pass basic format checks.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
function validateForm(form) {
    let valid = true;

    // Clear previous errors
    const invalidFields = form.querySelectorAll('.is-invalid');
    invalidFields.forEach(function (el) { el.classList.remove('is-invalid'); });
    const fieldErrors = form.querySelectorAll('.field-error');
    fieldErrors.forEach(function (el) { el.textContent = ''; });

    form.querySelectorAll('[required]').forEach(function (field) {
        if (!field.value.trim()) {
            markInvalid(field, 'This field is required.');
            valid = false;
        }
    });

    // Email format check
    form.querySelectorAll('input[type="email"]').forEach(function (field) {
        if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
            markInvalid(field, 'Enter a valid email address.');
            valid = false;
        }
    });

    // Password complexity (applies to fields with data-min-length — new/confirm password inputs)
    form.querySelectorAll('input[type="password"][data-min-length]').forEach(function (field) {
        if (!field.value) return;
        var missing = [];
        if (field.value.length < 10)           missing.push('at least 10 characters');
        if (!/[A-Z]/.test(field.value))         missing.push('an uppercase letter');
        if (!/[a-z]/.test(field.value))         missing.push('a lowercase letter');
        if (!/[0-9]/.test(field.value))         missing.push('a number');
        if (!/[^a-zA-Z0-9]/.test(field.value))  missing.push('a special character');
        if (field.id ==='password-login' || field.id ==='password-confirm'){
            return; // skip complexity check for login password field
        } else if (missing.length) {
            markInvalid(field, 'Password must contain ' + missing.join(', ') + '.');
            valid = false;
        }
    });

    // Phone format — SA number: 10 digits, starts with 0, second digit not 0,
    // and not all 10 digits the same (e.g. 0000000000).
    form.querySelectorAll('input[type="tel"]').forEach(function (field) {
        if (!field.value) return;
        var v   = field.value;
        var err = null;
        if (!/^0\d{9}$/.test(v)) {
            err = 'Phone number must be 10 digits and start with 0.';
        } else if (v[1] === '0') {
            err = 'The second digit of the phone number must not be 0.';
        } else if (/^(\d)\1{9}$/.test(v)) {
            err = 'Phone number cannot be all the same digit.';
        }
        if (err) { markInvalid(field, err); valid = false; }
    });

    // Alphanumeric fields (letters and numbers only)
    form.querySelectorAll('input[data-alphanumeric]').forEach(function (field) {
        if (field.value && !/^[a-zA-Z0-9]+$/.test(field.value)) {
            markInvalid(field, 'Must contain letters and numbers only.');
            valid = false;
        }
    });

    // SA ID validation
    form.querySelectorAll('input[data-sa-id]').forEach(function (field) {
        if (!field.value) return;
        var err = validateSaId(field.value);
        if (err) { markInvalid(field, err); valid = false; }
    });

    // Date of birth must not be in the future
    var dobField = form.querySelector('#dob, input[name="dob"], input[name="date_of_birth"]');
    if (dobField && dobField.value && dobField.value > today()) {
        markInvalid(dobField, 'Date of birth cannot be in the future.');
        valid = false;
    }

    // Emergency contact relationship — required when a contact name or phone is captured
    var ecRelationship = form.querySelector('#ec_relationship');
    if (ecRelationship) {
        var ecName  = form.querySelector('#ec_name');
        var ecPhone = form.querySelector('#ec_phone');
        var hasContact = ecName.value.trim() !== '' || ecPhone.value.trim() !== '';
        if (hasContact && !ecRelationship.value) {
            markInvalid(ecRelationship, 'Relationship is required when a contact name or phone is provided.');
            valid = false;
        }
    }

    // Scroll to first error
    const firstError = form.querySelector('.is-invalid');
    if (firstError) { firstError.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

    return valid;
}

/**
 * Validate a South African 13-digit ID number.
 * Mirrors the server-side validate_sa_id() in includes/auth.php.
 * Returns an error string, or null if valid.
 */
function validateSaId(id) {
    if (!/^\d{13}$/.test(id)) {
        return 'SA ID must be exactly 13 digits.';
    }

    var yy   = parseInt(id.substring(0, 2), 10);
    var mm   = parseInt(id.substring(2, 4), 10);
    var dd   = parseInt(id.substring(4, 6), 10);
    var year = yy <= new Date().getFullYear() % 100 ? 2000 + yy : 1900 + yy; // Convert YY to YYYY

    // Date of birth check
    var testDate = new Date(year, mm - 1, dd);
    if (testDate.getFullYear() !== year || testDate.getMonth() !== mm - 1 || testDate.getDate() !== dd) {
        return 'SA ID contains an invalid date of birth (digits 1–6 must be YYMMDD).';
    }

    // Citizenship digit (position 10, 0-indexed)
    if (id[10] !== '0' && id[10] !== '1') {
        return 'SA ID has an invalid citizenship digit (digit 11 must be 0 or 1).';
    }

    // Luhn check digit
    var sum = 0, alternate = false;
    for (var i = 12; i >= 0; i--) {
        var n = parseInt(id[i], 10);
        if (alternate) { n *= 2; if (n > 9) n -= 9; }
        sum += n;
        alternate = !alternate;
    }
    if (sum % 10 !== 0) {
        return 'SA ID number failed the check-digit validation — please verify the number.';
    }

    return null;
}

// Mark a field as invalid and show an error message
function markInvalid(field, message) {
    field.classList.add('is-invalid');
    const errorEl = field.parentElement.querySelector('.field-error');
    if (errorEl) { errorEl.textContent = message; }
}

// Mark a field as valid and clear any error message
function clearFieldError(field) {
    field.classList.remove('is-invalid');
    var errorEl = field.parentElement.querySelector('.field-error');
    if (errorEl) { errorEl.textContent = ''; }
}

// Clear a field's error as soon as the user edits it — full re-validation
// still happens again on the next submit attempt.
function initLiveErrorClearing() {
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('input', function (e) { handleLiveFieldChange(e.target); });
        form.addEventListener('change', function (e) { handleLiveFieldChange(e.target); });
    });
}

// Clear a field's error state when the user edits it, and also clear the emergency contact relationship error
function handleLiveFieldChange(field) {
    if (!field.classList) return;

    if (field.classList.contains('is-invalid')) {
        clearFieldError(field);
    }

    // Emergency contact relationship is only required jointly with name + phone,
    var form = field.closest('form');
    if (!form) return;
    var ecRelationship = form.querySelector('#ec_relationship');
    var ecName         = form.querySelector('#ec_name');
    var ecPhone        = form.querySelector('#ec_phone');
    if (ecRelationship && ecName && ecPhone &&
        (field === ecName || field === ecPhone || field === ecRelationship)) {
        var hasContact = ecName.value.trim() !== '' || ecPhone.value.trim() !== '';
        if (!(hasContact && !ecRelationship.value)) {
            clearFieldError(ecRelationship);
        }
    }
}

// Attach validateForm to all forms that have data-validate attribute,
// and auto-set required-field tooltips across the whole page.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!validateForm(form)) { e.preventDefault(); }
        });
    });

    setRequiredTooltips();
    initSaIdLiveValidation();
    initLiveErrorClearing();
});

/**
 * Walk every [required] field on the page and attach a native title tooltip
 * "This field <Label> is required" so the user knows why the border is red.
 * Also exposed globally so dynamic forms (e.g. staff_shifts onShiftTypeChange)
 * can call it after they toggle the required attribute.
 */
function setRequiredTooltips() {
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
        attachRequiredTooltip(field);
    });
}

function attachRequiredTooltip(field) {
    if (field.title) return; // already set
    var labelText = '';

    // 1. Preferred: <label for="fieldId">
    if (field.id) {
        var lbl = document.querySelector('label[for="' + field.id + '"]');
        if (lbl) labelText = lbl.textContent.trim();
    }
    // 2. Fallback: nearest label inside the same .form-group / div / td
    if (!labelText) {
        var container = field.closest('.form-group') || field.closest('td') || field.parentElement;
        if (container) {
            var lbl = container.querySelector('label');
            if (lbl) labelText = lbl.textContent.trim();
        }
    }
    // 3. Last resort: field name
    if (!labelText) labelText = field.name || field.id || 'this field';

    field.title = 'This field ' + labelText + ' is required';
}

// Date utilities 
function today() {
    return new Date().toISOString().split('T')[0];
}

/** Set the min attribute of a date input to today */
function setMinDateToday(inputId) {
    const el = document.getElementById(inputId);
    if (el) { el.min = today(); }
}

// Confirm delete helper
function confirmAction(message) {
    return window.confirm(message || 'Are you sure you want to proceed?');
}

// SA ID — live blur validation and auto-populate DOB + Gender
function initSaIdLiveValidation() {
    document.querySelectorAll('input[data-sa-id]').forEach(function (field) {
        var errEl = field.parentElement.querySelector('.field-error');

        // Clear error state while the user is still typing
        field.addEventListener('input', function () {
            field.classList.remove('is-invalid');
            if (errEl) errEl.textContent = '';
        });

        // Validate on blur; auto-populate if valid
        field.addEventListener('blur', function () {
            var val = field.value.trim();
            if (!val) {
                field.classList.remove('is-invalid');
                if (errEl) errEl.textContent = '';
                return;
            }

            var err = validateSaId(val);
            if (err) {
                field.classList.add('is-invalid');
                if (errEl) errEl.textContent = 'SA ID Number invalid, please try again.';
                return;
            }

            // Valid — clear error and fill DOB + Gender
            field.classList.remove('is-invalid');
            if (errEl) errEl.textContent = '';
            populateFromSaId(val, field.closest('form'));
        });
    });
}

function populateFromSaId(id, form) {
    if (!form) return;

    // Extract date of birth (YYMMDD → YYYY-MM-DD)
    var yy   = parseInt(id.substring(0, 2), 10);
    var mm   = id.substring(2, 4);
    var dd   = id.substring(4, 6);
    var year = yy <= new Date().getFullYear() % 100 ? 2000 + yy : 1900 + yy;
    var dob  = year + '-' + mm + '-' + dd;

    var dobField = form.querySelector('input[name="dob"], input[name="date_of_birth"]');
    if (dobField) {
        dobField.value = dob;
        flashField(dobField);
    }

    // Extract gender: digit at position 6 — 0-4 = Female, 5-9 = Male
    var genderField = form.querySelector('select[name="gender"]');
    if (genderField) {
        genderField.value = parseInt(id[6], 10) <= 4 ? 'Female' : 'Male';
        flashField(genderField);
    }
}

function flashField(el) {
    el.classList.add('field-autofilled');
    setTimeout(function () { el.classList.remove('field-autofilled'); }, 1400);
}
