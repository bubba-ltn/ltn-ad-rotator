jQuery(document).ready(function($){
    // Media library picker (delegated so it also works on newly added alternates)
    $(document).on('click', '.ltn-select-image', function(e){
        e.preventDefault();
        var target = $(this).data('target');
        var frame = wp.media({title:'Select or Upload Ad Image',button:{text:'Use this image'},multiple:false});
        frame.on('select', function(){
            var attachment = frame.state().get('selection').first().toJSON();
            $('#' + target).val(attachment.url);
        });
        frame.open();
    });

    // Add an alternate to a campaign
    var counter = 0;
    $(document).on('click', '.ltn-add-alt', function(e){
        e.preventDefault();
        var key = $(this).data('key');
        counter++;
        var html = $('#ltn-alt-template').html()
            .replace(/__KEY__/g, key)
            .replace(/__ID__/g, 'new' + Date.now() + counter);
        $('.ltn-alts[data-key="' + key + '"]').append(html);
    });

    // Remove an alternate (takes effect when Save Changes is clicked)
    $(document).on('click', '.ltn-remove-alt', function(e){
        e.preventDefault();
        $(this).closest('.ltn-alt-row').remove();
    });
});
