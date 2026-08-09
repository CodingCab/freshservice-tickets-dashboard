// Shared mixin: registers a Vue modal/panel on the global window.ModalStack
// (defined in public/js/app.js) so the single app-wide Escape handler can close
// ONLY the frontmost modal, one Esc-press at a time. Every modal that uses this
// mixin is dismissed by emitting `close` (the uniform contract in this app —
// the parent hides it via @close). No component-level Esc or backdrop-click
// close is needed anymore.
//
// Requirements on the host component:
//   - an `isOpen` prop or computed (Boolean) controlling visibility.

let seq = 0;

export default {
    data() {
        return { _modalStackId: 'vm' + (++seq) };
    },
    watch: {
        isOpen(open) {
            if (open) this._modalStackRegister();
            else this._modalStackUnregister();
        },
    },
    mounted() {
        if (this.isOpen) this._modalStackRegister();
    },
    beforeUnmount() {
        this._modalStackUnregister();
    },
    methods: {
        _modalStackRegister() {
            if (!window.ModalStack) return;
            window.ModalStack.push({
                id: this._modalStackId,
                close: () => { this.$emit('close'); },
            });
        },
        _modalStackUnregister() {
            if (window.ModalStack) window.ModalStack.remove(this._modalStackId);
        },
    },
};
