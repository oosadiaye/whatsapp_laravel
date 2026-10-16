// The browser bundle (Vite) lets modules import './call-state-mixin' without the ".js";
// Node's ESM loader does not. This hook retries with ".js" so the real call banners can be
// imported unchanged and tested (see register.mjs).
export async function resolve(specifier, context, nextResolve) {
    try {
        return await nextResolve(specifier, context);
    } catch (error) {
        if (error.code === 'ERR_MODULE_NOT_FOUND' && /^\.{1,2}\//.test(specifier) && !specifier.endsWith('.js')) {
            return nextResolve(`${specifier}.js`, context);
        }
        throw error;
    }
}
