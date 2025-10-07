/**
 * Tour Editor Module for Boarding
 * Handles tour editing functionality
 */
(function() {
    // Ensure Boarding namespace exists
    window.Boarding = window.Boarding || {};
    
    /**
     * Tour Editor class
     */
    window.Boarding.TourEdit = Garnish.Base.extend({
        $container: null,
        $stepContainers: null,
        stepCount: 0,
        primarySiteId: null,
        sortable: null,
        isNewTour: false,
    
        init: function(settings) {
            this.settings = settings;
            this.$container = $('#boarding-tour-edit');
            
            this.initStepManagement();
            
            this.initSortable();
        },

        initStepManagement: function() {
            const $addStepButton = this.$container.find('.js-add-step');
            
            $addStepButton.on('click', (e) => {
                e.preventDefault();
                this.addStep();
            });
            
            this.$container.on('click', '.js-add-step-above', (e) => {
                e.preventDefault();
                const $card = $(e.target).closest('.card');
                const index = this.$container.find('.card').index($card);
                this.addStepAt(index);
            });
            
            this.$container.on('click', '.js-add-step-below', (e) => {
                e.preventDefault();
                const $card = $(e.target).closest('.card');
                const index = this.$container.find('.card').index($card);
                this.addStepAt(index + 1);
            });
            
            this.$container.on('click', '.js-delete-step', (e) => {
                e.preventDefault();
                const $card = $(e.target).closest('.card');
                
                if (confirm('Are you sure you want to delete this step?')) {
                    $card.remove();
                    this.updateStepOrder();
                    this.refreshSortable();
                }
            });
                        
            this.$container.on('change', 'select[name$="[type]"]', (e) => {
                const $select = $(e.target);
                const $navigationFields = $select.closest('.card-body').find('.navigation-fields');
                
                if ($select.val() === 'navigation') {
                    $navigationFields.removeClass('hidden').addClass('visible');
                } else {
                    $navigationFields.removeClass('visible').addClass('hidden');
                }
            });
            
            this.$container.find('form').on('submit', (e) => {
                if (!this.validateForm()) {
                    e.preventDefault();
                }
            });
        },

        initSortable: function() {
            const $stepsContainer = this.$container.find('#tour-steps');
            
            if ($stepsContainer.length) {
                this.sortable = new Garnish.DragSort($stepsContainer.find('.card'), {
                    handle: '.move',
                    axis: 'y',
                    onSortChange: () => {
                        this.updateStepOrder();
                    }
                });
            }
        },

        addStep: function() {
            const $steps = this.$container.find('#tour-steps');
            const index = $steps.find('.card').length;
            
            const $newStep = $(this.getStepTemplate(index));
            $steps.append($newStep);
            
            this.refreshSortable();
        },
        
        addStepAt: function(index) {
            const $steps = this.$container.find('#tour-steps');
            const $cards = $steps.find('.card');
            const totalSteps = $cards.length;

            const $newStep = $(this.getStepTemplate(index));

            if (index <= 0) {
                $steps.prepend($newStep);
            } else if (index >= totalSteps) {
                $steps.append($newStep);
            } else {
                $cards.eq(index - 1).after($newStep);
            }

            this.updateStepOrder();

            this.refreshSortable();
        },

        updateStepOrder: function() {
            this.$container.find('.card').each((index, card) => {
                $(card).find('.card-header h4').text(`Step ${index + 1}`);
                
                $(card).find('input, select, textarea').each((i, field) => {
                    const $field = $(field);
                    const name = $field.attr('name');
                    if (name) {
                        const newName = name.replace(/steps\[\d+\]/, `steps[${index}]`);
                        $field.attr('name', newName);
                    }
                });
            });
        },
        
        refreshSortable: function() {
            if (this.sortable) {
                this.sortable.destroy();
            }

            this.initSortable();
        },

        /**
         * Validate the tour form before submission
         * @returns {boolean} True if validation passes
         */
        validateForm: function() {
            this.clearValidationErrors();

            let isValid = true;
            const errors = [];

            const $nameField = this.$container.find('input[name="name"]');
            if (!$nameField.val() || $nameField.val().trim() === '') {
                this.addFieldError($nameField, Craft.t('boarding', 'Tour name is required'));
                errors.push(Craft.t('boarding', 'Tour name is required'));
                isValid = false;
            }

            const $steps = this.$container.find('.card');
            if ($steps.length === 0) {
                errors.push(Craft.t('boarding', 'At least one step is required'));
                this.$container.find('.tour-validation-error').show();
                isValid = false;
            } else {
                this.$container.find('.tour-validation-error').hide();

                $steps.each((index, step) => {
                    const $step = $(step);
                    const stepNumber = index + 1;

                    const $titleField = $step.find('input[name*="[title]"]');
                    if (!$titleField.val() || $titleField.val().trim() === '') {
                        this.addFieldError($titleField, Craft.t('boarding', 'Step title is required'));
                        errors.push(Craft.t('boarding', 'Step {number}: Title is required', { number: stepNumber }));
                        isValid = false;
                    }

                    const $textField = $step.find('textarea[name*="[text]"]');
                    if (!$textField.val() || $textField.val().trim() === '') {
                        this.addFieldError($textField, Craft.t('boarding', 'Step content is required'));
                        errors.push(Craft.t('boarding', 'Step {number}: Content is required', { number: stepNumber }));
                        isValid = false;
                    }

                    const $typeField = $step.find('select[name*="[type]"]');
                    if ($typeField.val() === 'navigation') {
                        const $navUrlField = $step.find('input[name*="[navigationUrl]"]');
                        if (!$navUrlField.val() || $navUrlField.val().trim() === '') {
                            this.addFieldError($navUrlField, Craft.t('boarding', 'Navigation URL is required'));
                            errors.push(Craft.t('boarding', 'Step {number}: Navigation URL is required', { number: stepNumber }));
                            isValid = false;
                        }

                        const $navButtonField = $step.find('input[name*="[navigationButtonText]"]');
                        if (!$navButtonField.val() || $navButtonField.val().trim() === '') {
                            this.addFieldError($navButtonField, Craft.t('boarding', 'Navigation button text is required'));
                            errors.push(Craft.t('boarding', 'Step {number}: Navigation button text is required', { number: stepNumber }));
                            isValid = false;
                        }
                    }

                    const $elementField = $step.find('input[name*="[attachTo][element]"]');
                    if ($elementField.val() && $elementField.val().trim() !== '') {
                        const selector = $elementField.val().trim();
                        if (!this.isValidCssSelector(selector)) {
                            this.addFieldError($elementField, Craft.t('boarding', 'Invalid CSS selector'));
                            errors.push(Craft.t('boarding', 'Step {number}: Invalid CSS selector', { number: stepNumber }));
                            isValid = false;
                        }
                    }
                });
            }

            if (!isValid) {
                this.showValidationSummary(errors);
            }

            return isValid;
        },

        /**
         * Add error styling to a field
         * @param {jQuery} $field The field element
         * @param {string} message Error message
         */
        addFieldError: function($field, message) {
            $field.addClass('error');
            const $errorDiv = $('<div class="field-error">' + message + '</div>');
            $field.closest('.input').append($errorDiv);
        },

        /**
         * Clear all validation errors
         */
        clearValidationErrors: function() {
            this.$container.find('.error').removeClass('error');
            this.$container.find('.field-error').remove();
            this.$container.find('.validation-summary').remove();
        },

        /**
         * Show validation summary
         * @param {Array} errors Array of error messages
         */
        showValidationSummary: function(errors) {
            if (errors.length === 0) return;

            let summaryHtml = '<div class="validation-summary error">';
            summaryHtml += '<ul>';
            errors.forEach((error) => {
                summaryHtml += '<li>' + error + '</li>';
            });
            summaryHtml += '</ul></div>';

            this.$container.prepend(summaryHtml);

            $('html, body').animate({
                scrollTop: this.$container.offset().top - 100
            }, 500);
        },

        /**
         * Validate CSS selector syntax
         * @param {string} selector CSS selector to validate
         * @returns {boolean} True if valid
         */
        isValidCssSelector: function(selector) {
            try {
                document.querySelector(selector);
                return true;
            } catch (e) {
                return false;
            }
        },

        getStepTemplate: function(index) {
            return this.getTranslatableStepTemplate(index);
        },

        getTranslatableStepTemplate: function(index) {
            const contentsHtml = this.buildStepContentFields(index);

            return `
                <div class="tour-step card">
                    <div class="card-header">
                        <h4>Step ${index + 1}</h4>
                        <div class="card-actions">
                            <div class="move icon" title="Reorder"></div>
                            <button type="button" class="btn small icon js-add-step-above" title="Add step above">
                                <span data-icon="minus"></span>
                            </button>
                            <button type="button" class="btn small icon js-add-step-below" title="Add step below">
                                <span data-icon="plus"></span>
                            </button>
                            <button type="button" class="btn small icon delete js-delete-step" title="Delete step"></button>
                        </div>
                    </div>
                    <div class="card-body">
                        ${contentsHtml}
                    </div>
                </div>
            `;
        },
        
        buildStepContentFields: function(index) {
            let html = `
                <div class="field">
                    <div class="heading">
                        <label class="required">${Craft.t('boarding', 'Title')}</label>
                    </div>
                    <div class="input">
                        <input class="text fullwidth" type="text" name="steps[${index}][title]" value="">
                    </div>
                </div>
                <div class="field">
                    <div class="heading">
                        <label class="required">${Craft.t('boarding', 'Content')}</label>
                        <div class="instructions">${Craft.t('boarding', 'The content that will be shown to users in this step')}</div>
                    </div>
                    <div class="input">
                        <textarea class="nicetext text fullwidth" name="steps[${index}][text]" rows="3"></textarea>
                    </div>
                </div>
            `;
            
            html += `
                <div class="field">
                    <div class="heading">
                        <label>${Craft.t('boarding', 'Step Type')}</label>
                        <div class="instructions">${Craft.t('boarding', 'Choose whether this is a regular step or a navigation step')}</div>
                    </div>
                    <div class="input">
                        <div class="select">
                            <select name="steps[${index}][type]">
                                <option value="default" selected>${Craft.t('boarding', 'Default Step')}</option>
                                <option value="navigation">${Craft.t('boarding', 'Navigation Step')}</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;

            html += `
                <div class="navigation-fields hidden">
                    <div class="field">
                        <div class="heading">
                            <label>${Craft.t('boarding', 'Navigation URL')}</label>
                            <div class="instructions">${Craft.t('boarding', 'The URL to navigate to (e.g., /admin/assets)')}</div>
                        </div>
                        <div class="input">
                            <input class="text fullwidth" type="text" name="steps[${index}][navigationUrl]" value="">
                        </div>
                    </div>
                    <div class="field">
                        <div class="heading">
                            <label>${Craft.t('boarding', 'Navigation Button Text')}</label>
                            <div class="instructions">${Craft.t('boarding', 'The text to display on the navigation button')}</div>
                        </div>
                        <div class="input">
                            <input class="text fullwidth" type="text" name="steps[${index}][navigationButtonText]" value="">
                        </div>
                    </div>
                </div>
            `;

            html += `
                <div class="field">
                    <div class="heading">
                        <label>${Craft.t('boarding', 'Target Element')}</label>
                        <div class="instructions">${Craft.t('boarding', 'CSS selector for the target element (e.g. #nav-dashboard)')}</div>
                    </div>
                    <div class="input">
                        <input class="text fullwidth" type="text" name="steps[${index}][attachTo][element]" value="">
                    </div>
                </div>

                <div class="field">
                    <div class="heading">
                        <label>${Craft.t('boarding', 'Placement')}</label>
                        <div class="instructions">${Craft.t('boarding', 'Where the step should appear relative to the target element')}</div>
                    </div>
                    <div class="input ltr">
                        <div class="select">
                            <select name="steps[${index}][attachTo][on]">
                                <option value="top" selected>${Craft.t('boarding', 'Top')}</option>
                                <option value="bottom">${Craft.t('boarding', 'Bottom')}</option>
                                <option value="left">${Craft.t('boarding', 'Left')}</option>
                                <option value="right">${Craft.t('boarding', 'Right')}</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;

            return html;
        }
    });
    
    $(document).ready(function() {
        const $tourEdit = $('#boarding-tour-edit');
        if ($tourEdit.length) {
            if (!window.currentTourEdit) {
                try {
                    window.currentTourEdit = new window.Boarding.TourEdit({
                        isNewTour: $tourEdit.data('isNew') === 'true',
                        primarySiteId: $tourEdit.data('primarySiteId')
                    });
                } catch (error) {
                    console.error('Error initializing TourEdit:', error);
                }
            }
        }
    });
})();
