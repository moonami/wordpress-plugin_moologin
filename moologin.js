// purpose: register a moologin button in the rich editor in the admin interface
(function() {
    tinymce.create('tinymce.plugins.moologin', {
        init : function(ed, url) {
            ed.addButton('moologin', {
                title : 'MooLogin',
                image : url+'/icon.png',
                onclick : function() {
                     ed.selection.setContent('[moologin target=\"_self\" class=\"moologin\"]' + ed.selection.getContent() + '[/moologin]');
                }
            });
        },
        createControl : function(n, cm) {
            return null;
        },
    });
    tinymce.PluginManager.add('moologin', tinymce.plugins.moologin);
})();
