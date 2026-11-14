// Click-to-load map: <div x-data="mapEmbed"> (resources/views/components/venue-map.blade.php).
// The Google iframe sits in an x-if template, so nothing is requested from
// Google (and no cookie set) until the visitor presses "Show map". Focus
// moves to the map so keyboard users don't lose their place.
export default () => ({
    open: false,

    show() {
        this.open = true;
        this.$nextTick(() => this.$refs.frame?.focus());
    },
});
