// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['boite'];

  connect() {
    this.boite = new bootstrap.Modal(this.boiteTarget);
  }

  open() {
    this.boite.show();
  }

  close() {
    this.boite.hide();
  }
}