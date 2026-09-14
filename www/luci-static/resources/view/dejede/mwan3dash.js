"use strict";
"require view";

return view.extend({
    render: function() {
        var frame = E("iframe", {
            "src": "/mwan3dash/index.php",
            "style": "display:block;width:100%;height:calc(100vh - 125px);min-height:760px;border:0;background:#000;",
            "frameborder": "0",
            "scrolling": "auto"
        });

        return E("div", {
            "style": "margin:0;padding:0;width:100%;height:calc(100vh - 105px);min-height:760px;overflow:hidden;"
        }, [ frame ]);
    }
});
