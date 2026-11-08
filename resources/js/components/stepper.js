// Number stepper around a native <input type="number"> (x-ref="input").
// Bounds come from data-min / data-max on the x-data element. Without
// JavaScript the input still works; the buttons stay hidden (x-cloak).
export default () => ({
    value: 1,
    min: 1,
    max: 1,

    init() {
        this.min = Number(this.$el.dataset.min ?? 1);
        this.max = Number(this.$el.dataset.max ?? this.min);
        this.value = Number(this.$refs.input.value || this.min);
        this.$refs.input.addEventListener('input', () => {
            this.value = Number(this.$refs.input.value || this.min);
        });
    },

    get atMin() {
        return this.value <= this.min;
    },

    get atMax() {
        return this.value >= this.max;
    },

    increment() {
        this.set(this.value + 1);
    },

    decrement() {
        this.set(this.value - 1);
    },

    set(next) {
        this.value = Math.min(this.max, Math.max(this.min, next));
        this.$refs.input.value = String(this.value);
        this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
    },
});
