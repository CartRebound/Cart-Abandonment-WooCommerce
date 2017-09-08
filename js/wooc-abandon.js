console.log("Hi it's woocabandon!");
jQuery(function($){
    console.log("Hi it's jq!");

    var $eml = $("#billing_email");

    $eml.on("keyup", function(){
        var eml = $(this).val();



        // console.log(eml);

        if(woocAbandon.validateEmail(eml)){
            $.post("/wp-admin/admin-ajax.php?action=capture_woocabandon_email", {
                email: eml,
                time: (+new Date())
            }, function (s) {
                console.log("woocabandonemail response", s);
            });
        }

    });

    var woocAbandon = {
        validateEmail: function(email) {
            var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }
    };

});