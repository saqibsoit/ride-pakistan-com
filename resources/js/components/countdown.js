// Time left until data-deadline (ISO 8601): "5 h 12 min left". The exact
// deadline is always printed next to it, so this is only a convenience.
// Updates once a minute (no motion, nothing announced).
export default () => ({
    text: '',
    timer: null,

    init() {
        this.update();
        this.timer = setInterval(() => this.update(), 60000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    update() {
        const minutes = Math.floor((Date.parse(this.$root.dataset.deadline) - Date.now()) / 60000);

        if (Number.isNaN(minutes) || minutes <= 0) {
            this.text = 'Deadline passed';
            clearInterval(this.timer);
            return;
        }

        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;

        this.text = (hours > 0 ? `${hours} h ` : '') + `${rest} min left`;
    },
});
