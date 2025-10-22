/**
 * Tour Editor - Modern Tour Editing Interface
 *
 * Handles tour editing functionality using vanilla JavaScript.
 * No jQuery dependencies.
 *
 * @version 2.0.0
 */
class TourEditor {
    constructor(settings = {}) {
        this.settings = settings;
        this.container = document.getElementById('boarding-tour-edit');

        if (!this.container) {
            console.error('TourEditor: Container element not found');
            return;
        }

        this.sortable = null;
        this.init();
    }

    /**
     * Initialize the editor
     */
    init() {
        this.initStepManagement();
        this.initSortable();
        this.initFormValidation();
    }

    /**
     * Initialize step management (add, delete, reorder)
     */
    initStepManagement() {
        // Add step button
        const addStepButton = this.container.querySelector('.js-add-step');
        if (addStepButton) {
            addStepButton.addEventListener('click', e => {
                e.preventDefault();
                this.addStep();
            });
        }

        // Event delegation for step actions
        this.container.addEventListener('click', e => {
            const target = e.target.closest('button');
            if (!target) return;

            // Add step above
            if (target.classList.contains('js-add-step-above')) {
                e.preventDefault();
                const card = target.closest('.card');
                const index = Array.from(this.container.querySelectorAll('.card')).indexOf(card);
                this.addStepAt(index);
            }

            // Add step below
            else if (target.classList.contains('js-add-step-below')) {
                e.preventDefault();
                const card = target.closest('.card');
                const index = Array.from(this.container.querySelectorAll('.card')).indexOf(card);
                this.addStepAt(index + 1);
            }

            // Delete step
            else if (target.classList.contains('js-delete-step')) {
                e.preventDefault();
                if (confirm('Are you sure you want to delete this step?')) {
                    const card = target.closest('.card');
                    card.remove();
                    this.updateStepOrder();
                    this.refreshSortable();
                }
            }
        });

        // Handle step type change
        this.container.addEventListener('change', e => {
            if (e.target.matches('select[name$="[type]"]')) {
                const select = e.target;
                const navigationFields = select.closest('.card-body').querySelector('.navigation-fields');

                if (navigationFields) {
                    if (select.value === 'navigation') {
                        navigationFields.classList.remove('hidden');
                        navigationFields.classList.add('visible');
                    } else {
                        navigationFields.classList.remove('visible');
                        navigationFields.classList.add('hidden');
                    }
                }
            }
        });
    }

    /**
     * Initialize sortable functionality
     */
    initSortable() {
        const stepsContainer = this.container.querySelector('#tour-steps');

        if (stepsContainer && typeof Garnish !== 'undefined') {
            const cards = stepsContainer.querySelectorAll('.card');
            this.sortable = new Garnish.DragSort(cards, {
                handle: '.move',
                axis: 'y',
                onSortChange: () => {
                    this.updateStepOrder();
                }
            });
        }
    }

    /**
     * Refresh sortable after DOM changes
     */
    refreshSortable() {
        if (this.sortable) {
            this.sortable.destroy();
        }
        this.initSortable();
    }

    /**
     * Initialize form validation
     */
    initFormValidation() {
        const form = this.container.querySelector('form');
        if (form) {
            form.addEventListener('submit', e => {
                if (!this.validateForm()) {
                    e.preventDefault();
                }
            });
        }
    }

    /**
     * Add a new step at the end
     */
    addStep() {
        const stepsContainer = this.container.querySelector('#tour-steps');
        const index = stepsContainer.querySelectorAll('.card').length;

        const newStep = this.createStepElement(index);
        stepsContainer.appendChild(newStep);

        this.refreshSortable();
    }

    /**
     * Add a step at a specific position
     */
    addStepAt(index) {
        const stepsContainer = this.container.querySelector('#tour-steps');
        const cards = Array.from(stepsContainer.querySelectorAll('.card'));
        const totalSteps = cards.length;

        const newStep = this.createStepElement(index);

        if (index <= 0) {
            stepsContainer.insertBefore(newStep, stepsContainer.firstChild);
        } else if (index >= totalSteps) {
            stepsContainer.appendChild(newStep);
        } else {
            stepsContainer.insertBefore(newStep, cards[index]);
        }

        this.updateStepOrder();
        this.refreshSortable();
    }

    /**
     * Update step numbering and input names
     */
    updateStepOrder() {
        const cards = this.container.querySelectorAll('.card');

        cards.forEach((card, index) => {
            // Update header
            const header = card.querySelector('.card-header h4');
            if (header) {
                header.textContent = `Step ${index + 1}`;
            }

            // Update input names
            card.querySelectorAll('input, select, textarea').forEach(field => {
                const name = field.getAttribute('name');
                if (name) {
                    const newName = name.replace(/steps\[\d+\]/, `steps[${index}]`);
                    field.setAttribute('name', newName);
                }
            });
        });
    }

    /**
     * Create a new step element
     */
    createStepElement(index) {
        const div = document.createElement('div');
        div.className = 'tour-step card';
        div.innerHTML = this.getStepTemplate(index);
        return div;
    }

    /**
     * Get step HTML template
     */
    getStepTemplate(index) {
        return `
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
                ${this.buildStepContentFields(index)}
            </div>
        `;
    }

    /**
     * Build step content fields HTML
     */
    buildStepContentFields(index) {
        return `
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
    }

    /**
     * Validate form before submission
     */
    validateForm() {
        this.clearValidationErrors();

        const errors = [];
        let isValid = true;

        // Validate tour name
        const nameField = this.container.querySelector('input[name="name"]');
        if (!nameField || !nameField.value.trim()) {
            this.addFieldError(nameField, Craft.t('boarding', 'Tour name is required'));
            errors.push(Craft.t('boarding', 'Tour name is required'));
            isValid = false;
        }

        // Validate steps
        const steps = this.container.querySelectorAll('.card');
        if (steps.length === 0) {
            errors.push(Craft.t('boarding', 'At least one step is required'));
            isValid = false;
        } else {
            steps.forEach((step, index) => {
                const stepNumber = index + 1;

                // Validate title
                const titleField = step.querySelector('input[name*="[title]"]');
                if (!titleField || !titleField.value.trim()) {
                    this.addFieldError(titleField, Craft.t('boarding', 'Step title is required'));
                    errors.push(Craft.t('boarding', 'Step {number}: Title is required', { number: stepNumber }));
                    isValid = false;
                }

                // Validate content
                const textField = step.querySelector('textarea[name*="[text]"]');
                if (!textField || !textField.value.trim()) {
                    this.addFieldError(textField, Craft.t('boarding', 'Step content is required'));
                    errors.push(Craft.t('boarding', 'Step {number}: Content is required', { number: stepNumber }));
                    isValid = false;
                }

                // Validate navigation fields
                const typeField = step.querySelector('select[name*="[type]"]');
                if (typeField && typeField.value === 'navigation') {
                    const navUrlField = step.querySelector('input[name*="[navigationUrl]"]');
                    if (!navUrlField || !navUrlField.value.trim()) {
                        this.addFieldError(navUrlField, Craft.t('boarding', 'Navigation URL is required'));
                        errors.push(Craft.t('boarding', 'Step {number}: Navigation URL is required', { number: stepNumber }));
                        isValid = false;
                    }

                    const navButtonField = step.querySelector('input[name*="[navigationButtonText]"]');
                    if (!navButtonField || !navButtonField.value.trim()) {
                        this.addFieldError(navButtonField, Craft.t('boarding', 'Navigation button text is required'));
                        errors.push(Craft.t('boarding', 'Step {number}: Navigation button text is required', { number: stepNumber }));
                        isValid = false;
                    }
                }

                // Validate CSS selector
                const elementField = step.querySelector('input[name*="[attachTo][element]"]');
                if (elementField && elementField.value.trim()) {
                    const selector = elementField.value.trim();
                    if (!this.isValidCssSelector(selector)) {
                        this.addFieldError(elementField, Craft.t('boarding', 'Invalid CSS selector'));
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
    }

    /**
     * Add error styling to a field
     */
    addFieldError(field, message) {
        if (!field) return;

        field.classList.add('error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.textContent = message;

        const inputContainer = field.closest('.input');
        if (inputContainer) {
            inputContainer.appendChild(errorDiv);
        }
    }

    /**
     * Clear all validation errors
     */
    clearValidationErrors() {
        this.container.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
        this.container.querySelectorAll('.field-error').forEach(el => el.remove());
        this.container.querySelectorAll('.validation-summary').forEach(el => el.remove());
    }

    /**
     * Show validation summary
     */
    showValidationSummary(errors) {
        if (errors.length === 0) return;

        const summary = document.createElement('div');
        summary.className = 'validation-summary error';

        const ul = document.createElement('ul');
        errors.forEach(error => {
            const li = document.createElement('li');
            li.textContent = error;
            ul.appendChild(li);
        });

        summary.appendChild(ul);
        this.container.insertBefore(summary, this.container.firstChild);

        // Scroll to error summary
        const containerTop = this.container.getBoundingClientRect().top + window.pageYOffset;
        window.scrollTo({
            top: containerTop - 100,
            behavior: 'smooth'
        });
    }

    /**
     * Validate CSS selector
     */
    isValidCssSelector(selector) {
        try {
            document.querySelector(selector);
            return true;
        } catch (e) {
            return false;
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        const tourEditContainer = document.getElementById('boarding-tour-edit');
        if (tourEditContainer) {
            window.currentTourEdit = new TourEditor({
                isNewTour: tourEditContainer.dataset.isNew === 'true',
                primarySiteId: tourEditContainer.dataset.primarySiteId
            });
        }
    });
} else {
    const tourEditContainer = document.getElementById('boarding-tour-edit');
    if (tourEditContainer) {
        window.currentTourEdit = new TourEditor({
            isNewTour: tourEditContainer.dataset.isNew === 'true',
            primarySiteId: tourEditContainer.dataset.primarySiteId
        });
    }
}
