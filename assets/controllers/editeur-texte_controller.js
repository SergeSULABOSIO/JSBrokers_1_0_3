import { Controller } from '@hotwired/stimulus';
import { tinymce } from '/tinymce';

export default class extends Controller {
    connect() {
        tinymce.init({
            target: this.element,
            plugins: 'lists link image table wordcount',
            toolbar: 'undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image table',
        });
    }
}