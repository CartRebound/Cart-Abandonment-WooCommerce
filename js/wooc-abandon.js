jQuery(function($){
    var $eml = $("#billing_email");
    $eml.on("keyup", function(){
        var eml = $(this).val();

        if(cartRebound.validateEmail(eml)){
            $.post("/wp-admin/admin-ajax.php?action=capture_cartrebound_email", {
                email: eml,
                time: (+new Date())
            }, function (s) {
            });
        }

    });

    var cartRebound = {
        validateEmail: function(email) {
            var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }
    };

});