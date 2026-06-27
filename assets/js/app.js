/**
 * PSB Kreditverwaltung - JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Copy-to-Clipboard Buttons
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.copyTarget;
            const target = document.getElementById(targetId);
            if (!target) return;

            const text = target.value || target.textContent;
            const self = this;

            function markCopied() {
                self.classList.add('copied');
                setTimeout(() => self.classList.remove('copied'), 1500);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(markCopied).catch(() => fallbackCopy(text, markCopied));
            } else {
                fallbackCopy(text, markCopied);
            }
        });
    });

    function fallbackCopy(text, callback) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); if (callback) callback(); } catch(e) {}
        document.body.removeChild(ta);
    }

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // Confirm delete actions
    document.querySelectorAll('[data-confirm]').forEach(elem => {
        elem.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Format currency inputs
    document.querySelectorAll('.currency-input').forEach(input => {
        input.addEventListener('blur', function() {
            let value = parseFloat(this.value.replace(/[^\d.,]/g, '').replace(',', '.'));
            if (!isNaN(value)) {
                this.value = Math.round(value);
            }
        });
    });

    // Auto-calculate loan fields
    const loanForm = document.getElementById('loanForm');
    if (loanForm) {
        const purchasePrice = document.getElementById('purchase_price');
        const downPayment = document.getElementById('down_payment');
        const loanAmount = document.getElementById('loan_amount');
        const interestRate = document.getElementById('interest_rate');
        const termWeeks = document.getElementById('term_weeks');
        const weeklyRate = document.getElementById('weekly_rate');
        const totalInterest = document.getElementById('total_interest');
        const totalAmount = document.getElementById('total_amount');

        function calculateLoan() {
            const pp = parseFloat(purchasePrice?.value) || 0;
            const dp = parseFloat(downPayment?.value) || 0;
            const la = pp - dp;
            const ir = parseFloat(interestRate?.value) || 0;
            const tw = parseInt(termWeeks?.value) || 1;

            if (loanAmount) loanAmount.value = Math.round(la);

            const ti = Math.round(la * (ir / 100));
            const ta = Math.round(la + ti);
            const wr = Math.round(ta / tw);

            if (totalInterest) totalInterest.value = ti;
            if (totalAmount) totalAmount.value = ta;
            if (weeklyRate) weeklyRate.value = wr;
        }

        [purchasePrice, downPayment, interestRate, termWeeks].forEach(input => {
            if (input) input.addEventListener('input', calculateLoan);
        });
    }

    // Search filter for tables
    const searchInputs = document.querySelectorAll('[data-search-table]');
    searchInputs.forEach(input => {
        const tableId = input.dataset.searchTable;
        const table = document.getElementById(tableId);

        if (table) {
            input.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
    });

    // Template placeholder replacement preview
    const templateSelect = document.getElementById('template_select');
    const previewArea = document.getElementById('template_preview');

    if (templateSelect && previewArea) {
        templateSelect.addEventListener('change', function() {
            const templateId = this.value;
            if (templateId) {
                fetch(`${APP_URL}/api/template.php?id=${templateId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.body) {
                            previewArea.value = data.body;
                        }
                    });
            }
        });
    }

    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(t => new bootstrap.Tooltip(t));
});

// Format money helper
function formatMoney(amount) {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(Math.round(amount));
}

// Show loading state on form submit
function showLoading(form) {
    const btn = form.querySelector('[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner me-2"></span>Bitte warten...';
    }
}
