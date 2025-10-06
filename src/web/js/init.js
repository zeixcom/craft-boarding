/**
 * Boarding Initialize Module
 * Handles initialization of the Boarding plugin
 */
(function() {
    window.Boarding = window.Boarding || {};
    
    $(document).ready(function() {
        if (typeof Boarding.initTours === 'function') {
            Boarding.initTours();
        } else {
            console.error('Error initializing tours.');
        }
    });
})();