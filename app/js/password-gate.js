(function () {
    var target = OC.generateUrl('/apps/w3ds_login/password-setup');
    if (window.location.pathname.indexOf(target) !== 0) {
        window.location.replace(target);
    }
})();
