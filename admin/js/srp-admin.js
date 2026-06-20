jQuery(function($) {
    // Copy URL button
    $(document).on('click', '.srp-copy-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
                var btn = e.target;
                var original = btn.textContent;
                btn.textContent = 'Copié !';
                setTimeout(function() { btn.textContent = original; }, 1500);
            });
        }
    });

    // Test link button
    $('#srp-test-link').on('click', function(e) {
        e.preventDefault();
        var url = $('input[name="srp_destination_url"]').val();
        if (url) window.open(url, '_blank');
    });

    // Update test link href when destination changes
    $('input[name="srp_destination_url"]').on('input', function() {
        $('#srp-test-link').attr('href', $(this).val() || '#');
    });
});
