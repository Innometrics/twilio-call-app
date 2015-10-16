/* global makeSettingsEditor, Loader */

(function () {
    makeSettingsEditor({
        form: $('#form-setting')[0],
        submit: $('#submit-setting')
    }, {
        schemaPath: 'js/settings.schema.json',
        title: 'App settings stored in Innometrics Cloud'
    }, {
        callbackGetSettings: function (helper, form) {
            helper.getProperties(function (status, data) {
                if (status) {
                    form.setValue(data);
                } else {
                    console.log('Error: unable to get Settings from Profile Cloud');
                }
                Loader.hide();
            });
        },
        callbackSetSettings: function (helper, form) {
            Loader.show('Saving...');
            helper.setProperties(form.getValue(), function (status) {
                if (status) {
                    console.log('Settings were saved.');
                }
                Loader.hide();
            });
        }
    });

})();
