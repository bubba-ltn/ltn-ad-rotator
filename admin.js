jQuery(document).ready(function($){
    $('.ltn-select-image').on('click', function(e){
        e.preventDefault();
        var target = $(this).data('target');
        var frame = wp.media({title:'Select or Upload Ad Image',button:{text:'Use this image'},multiple:false});
        frame.on('select', function(){
            var attachment = frame.state().get('selection').first().toJSON();
            $('#' + target).val(attachment.url);
        });
        frame.open();
    });
});