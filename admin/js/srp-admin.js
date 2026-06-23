jQuery(function($) {

    var __ = wp.i18n.__;

    function copyToClipboard(text, btn) {
        var $btn = $(btn);
        var original = $btn.text();

        function onSuccess() {
            $btn.text(__('Copied!', 'smart-redirect-pro'));
            setTimeout(function() { $btn.text(original); }, 1500);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(onSuccess).catch(function() {
                fallbackCopy(text, onSuccess);
            });
        } else {
            fallbackCopy(text, onSuccess);
        }
    }

    function fallbackCopy(text, callback) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            if (callback) callback();
        } catch(e) {}
        document.body.removeChild(ta);
    }

    // Copy URL button
    $(document).on('click', '.srp-copy-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        if (url) copyToClipboard(url, this);
    });

    // Test link button
    $(document).on('click', '#srp-test-link', function(e) {
        e.preventDefault();
        var url = $('input[name="srp_destination_url"]').val();
        if (url) window.open(url, '_blank');
    });

    // Update test link href when destination changes
    $('input[name="srp_destination_url"]').on('input', function() {
        $('#srp-test-link').attr('href', $(this).val() || '#');
    });

    // Live update URL, QR code, and shortcode when slug changes
    var $slugInput = $('#srp-custom-slug');
    if ($slugInput.length) {
        var debounceTimer;
        var baseUrl = $slugInput.closest('.srp-admin-section').find('span').first().text().trim();

        $slugInput.on('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                var slug = $slugInput.val().trim().toLowerCase()
                    .replace(/[^a-z0-9\-]/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');

                if (!slug) return;

                var fullUrl = baseUrl + slug + '/';

                // Update URL display
                $('#srp-short-url').text(fullUrl);

                // Update copy button data
                $('#srp-copy-url-btn').data('url', fullUrl);

                // Update QR code + download link
                var encodedUrl = encodeURIComponent(fullUrl);
                $('#srp-qr-img').attr('src', 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodedUrl);
                $('#srp-qr-download').attr('href', 'https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&format=png&data=' + encodedUrl).attr('download', 'qr-' + slug + '.png');

                // Update shortcode
                $('#srp-shortcode-display').text('[srp_link slug="' + slug + '"]');
            }, 300);
        });
    }
});
