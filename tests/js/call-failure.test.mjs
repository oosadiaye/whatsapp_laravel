import test from 'node:test';
import assert from 'node:assert/strict';
import { describeCallFailure } from '../../resources/js/call-failure.js';

// Reasons are the strings the webhook stores in call_logs.failure_reason, built from
// Africa's Talking's own fields: "AT bridge: <lastBridgeHangupCause>" and
// "AT failure: <errorMessage|hangupCause>".

test('returns nothing when there is no reason, so the banner just closes', () => {
    assert.equal(describeCallFailure(null), '');
    assert.equal(describeCallFailure(undefined), '');
    assert.equal(describeCallFailure('   '), '');
});

test('NO_USER_RESPONSE: AT never reached the browser', () => {
    const msg = describeCallFailure('AT bridge: NO_USER_RESPONSE');
    assert.match(msg, /never reached/i);
    assert.match(msg, /one (BlastIQ )?tab|only one/i, 'points at the competing-tabs cause');
});

test('NO_ANSWER: the browser rang but was not answered', () => {
    assert.match(describeCallFailure('AT bridge: NO_ANSWER'), /rang|not answered|in time/i);
});

test('RECOVERY_ON_TIMER_EXPIRE: answered, but the audio link never formed', () => {
    const msg = describeCallFailure('AT bridge: RECOVERY_ON_TIMER_EXPIRE');
    assert.match(msg, /audio/i);
    assert.match(msg, /microphone|network|firewall|voice setup/i);
});

test('keeps the raw provider code visible for support', () => {
    assert.match(describeCallFailure('AT bridge: NO_USER_RESPONSE'), /NO_USER_RESPONSE/);
    assert.match(describeCallFailure('AT bridge: RECOVERY_ON_TIMER_EXPIRE'), /RECOVERY_ON_TIMER_EXPIRE/);
});

test('passes AT\'s own wording through when we have no friendlier version', () => {
    const msg = describeCallFailure('AT failure: Your account is not authorized to use the callerId [+2347045716987]');
    assert.match(msg, /not authorized to use the callerId/);
    assert.ok(!msg.startsWith('AT failure:'), 'internal prefix is not shown to the agent');
});

test('an unknown bridge cause is still shown rather than hidden', () => {
    assert.match(describeCallFailure('AT bridge: SOMETHING_NEW'), /SOMETHING_NEW/);
});
