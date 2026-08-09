<template>
    <div v-if="isOpen" class="td-overlay">
        <aside class="td-panel" role="dialog" aria-label="Ticket detail">
            <header class="td-header">
                <div class="td-header-title">
                    <button
                        type="button"
                        class="td-star"
                        :class="{ 'td-star-on': isStarred }"
                        :title="isStarred ? 'Unstar this ticket' : 'Star this ticket'"
                        @click="onToggleStar"
                    >{{ isStarred ? '★' : '☆' }}</button>
                    <span class="td-id">{{ ticketId }}</span>
                    <span v-if="data && data.pipeline_section" class="td-stage" :class="pipelineStageClass" :title="'Current pipeline section: ## ' + data.pipeline_section">{{ data.pipeline_section }}</span>
                    <span v-if="data && data.path" class="td-path" :title="data.path">{{ shortPath }}</span>
                </div>
                <button class="td-close" type="button" aria-label="Close" @click="close">&times;</button>
            </header>

            <!-- Labels + actions belong to a ticket we actually have. While the
                 ticket is still loading or being ingested from FreshService there
                 is nothing to label and nothing to act on, so both stay hidden
                 rather than offering controls over a ticket that isn't there. -->
            <div v-if="data" class="td-labels">
                <span class="td-labels-caption">Labels:</span>
                <span v-for="l in ticketLabels" :key="l.id" class="label-chip" :style="{ '--lc': l.color }">{{ l.name }}</span>
                <span v-if="!ticketLabels.length" class="td-labels-none">none</span>
                <button type="button" class="td-labels-add" title="Assign labels" @click="openLabels">+ Labels</button>
            </div>

            <ticket-actions v-if="data" :data="data" @action="onAction" />

            <div v-if="agentRequestBanner" class="agent-request-banner" :class="'agent-request-banner--' + agentRequestBanner.tone">
                <div class="agent-request-banner__head">
                    <span class="agent-request-banner__badge">🤖 {{ agentRequestBanner.label }}</span>
                    <span v-if="agentRequestBanner.elapsed" class="agent-request-banner__elapsed">{{ agentRequestBanner.elapsed }}</span>
                    <button v-if="agentRequestBanner.canRefresh" type="button" class="agent-request-banner__refresh" :disabled="loading" @click="load">↻ Refresh</button>
                </div>
                <div class="agent-request-banner__instruction">
                    <strong>Instruction:</strong> {{ agentRequestBanner.instruction }}
                </div>
                <div v-if="agentRequestBanner.lastActivity" class="agent-request-banner__activity">
                    <strong>Last activity:</strong> {{ agentRequestBanner.lastActivity }}
                </div>
            </div>

            <reject-feedback-modal
                :ticket-id="ticketId"
                :is-open="rejectModalOpen"
                @close="rejectModalOpen = false"
                @rejected="onRejected"
            />

            <reply-draft-modal
                :ticket-id="ticketId"
                :is-open="sendModalOpen"
                @close="sendModalOpen = false"
                @sent="onReplySent"
                @armed="onAutoSendArmed"
                @reject-requested="onRejectRequested"
            />

            <split-ticket-modal
                :ticket-id="ticketId"
                :is-open="splitModalOpen"
                :requester-display="(data && data.metadata && data.metadata.Requester) || ''"
                :cc-display="(data && data.metadata && data.metadata.CC) || ''"
                :available-attachments="allConversationAttachments"
                @close="splitModalOpen = false"
                @split="onSplit"
            />

            <confirm-modal
                :is-open="humanReviewModalOpen"
                title="Override to Human Review"
                :message="'This will move ticket ' + ticketId + ' to the Human Review section. The pipeline will pause automated handling until a human reroutes it.'"
                confirm-label="Override"
                confirm-variant="primary"
                :require-reason="true"
                :busy="humanReviewBusy"
                @close="humanReviewModalOpen = false"
                @confirm="onHumanReviewConfirm"
            />

            <manual-reply-modal
                :ticket-id="ticketId"
                :is-open="manualReplyModalOpen"
                :to-email="manualReplyToEmail"
                :cc-emails="manualReplyCcEmails"
                :subject-line="manualReplySubject"
                @close="manualReplyModalOpen = false"
                @sent="onManualReplySent"
            />

            <subtask-viewer-modal
                :ticket-id="ticketId"
                :filename="subtaskModalFilename"
                :title="subtaskModalTitle"
                :is-open="subtaskModalOpen"
                @close="subtaskModalOpen = false"
            />

            <subtask-viewer-modal
                label="Task"
                :url="relatedModalUrl"
                :filename="relatedModalPath"
                :title="relatedModalTitle"
                :is-open="relatedModalOpen"
                @close="relatedModalOpen = false"
            />

            <confirm-modal
                :is-open="statusModalOpen"
                :title="statusModalTitle"
                :message="statusModalMessage"
                :confirm-label="statusModalConfirmLabel"
                :confirm-variant="statusModalConfirmVariant"
                :busy="statusBusy"
                @close="statusModalOpen = false"
                @confirm="onStatusConfirm"
            />

            <confirm-modal
                :is-open="aiComposeModalOpen"
                title="Ask the agent to draft"
                :message="aiComposeMessage"
                :reason-placeholder="aiComposePlaceholder"
                confirm-label="Send to agent"
                confirm-variant="primary"
                :require-reason="true"
                :reason-required="true"
                :busy="aiComposeBusy"
                @close="aiComposeModalOpen = false"
                @confirm="onAiComposeConfirm"
            />

            <confirm-modal
                :is-open="automationFeedbackModalOpen"
                title="Report an automation issue"
                :message="automationFeedbackMessage"
                :reason-placeholder="automationFeedbackPlaceholder"
                confirm-label="Save feedback"
                confirm-variant="primary"
                :require-reason="true"
                :reason-required="true"
                :busy="automationFeedbackBusy"
                @close="automationFeedbackModalOpen = false"
                @confirm="onAutomationFeedbackConfirm"
            />

            <knowledge-fact-modal
                :is-open="knowledgeFactModalOpen"
                :busy="knowledgeFactBusy"
                @close="knowledgeFactModalOpen = false"
                @confirm="onKnowledgeFactConfirm"
            />

            <div v-if="toastMessage" class="td-toast" role="status">{{ toastMessage }}</div>

            <div class="td-body">
                <div v-if="loading" class="td-empty">Loading ticket {{ ticketId }}...</div>
                <div v-else-if="ingesting && !data" class="td-empty">🔄 {{ ingesting }}</div>
                <div v-else-if="error" class="td-empty td-error">Failed to load: {{ error }}</div>
                <template v-else-if="data">
                    <!-- Tenant-version status banner — shown when the ticket is parked
                         waiting on a tenant upgrade (orchestrator set `awaiting_version:`
                         in the ticket frontmatter), or when the watcher has just
                         recorded that the upgrade landed. -->
                    <section v-if="data.version_status" class="td-section td-version-banner" :class="'td-version-' + data.version_status.state">
                        <div class="td-version-icon">{{ data.version_status.state === 'awaiting' ? '⏳' : '🚀' }}</div>
                        <div class="td-version-text">
                            <div class="td-version-headline">
                                <template v-if="data.version_status.state === 'awaiting'">
                                    Waiting on tenant upgrade to <strong>{{ data.version_status.awaiting_version }}</strong>
                                </template>
                                <template v-else>
                                    Tenant reached <strong>{{ data.version_status.tenant_release }}</strong> — follow-up due
                                </template>
                            </div>
                            <div class="td-version-detail">
                                <template v-if="data.version_status.state === 'awaiting'">
                                    <span v-if="data.version_status.tenant_current_release">
                                        Tenant currently on <strong>{{ data.version_status.tenant_current_release }}</strong>.
                                    </span>
                                    The daily watcher (06:00 UTC) checks every morning; when the tenant catches up, this ticket auto-bounces to Reply Drafting with a "fix is now live" follow-up. No manual action needed.
                                    <span v-if="data.version_status.set_at" class="td-version-meta">
                                        · Flagged {{ data.version_status.set_at }}
                                    </span>
                                </template>
                                <template v-else>
                                    The upgrade reached the tenant
                                    <span v-if="data.version_status.landed_at">on {{ data.version_status.landed_at }}</span>.
                                    The follow-up reply should already be in Reply Drafting (or further along the pipeline).
                                </template>
                            </div>
                        </div>
                    </section>

                    <!-- Operator note — single editable internal note, no history. -->
                    <section class="td-section td-opnote">
                        <div class="td-opnote-head">
                            <span class="td-opnote-label">Internal note</span>
                            <span v-if="operatorNoteStatus" class="td-opnote-status">{{ operatorNoteStatus }}</span>
                            <button
                                type="button"
                                class="td-opnote-save"
                                :disabled="!operatorNoteDirty || operatorNoteSaving"
                                @click="saveOperatorNote"
                            >{{ operatorNoteSaving ? 'Saving…' : 'Save' }}</button>
                        </div>
                        <textarea
                            ref="opNoteRef"
                            class="td-opnote-text"
                            v-model="operatorNote"
                            placeholder="Internal note for this ticket — only you see it. Click Save to store."
                            rows="2"
                            @input="operatorNoteDirty = true; autosizeOperatorNote()"
                        ></textarea>
                    </section>

                    <!-- Internal note to FreshService — append-only. Posts a
                         private note to the FS ticket AND shows in the
                         conversation stream below (via sync). -->
                    <section class="td-section td-fsnote">
                        <div class="td-fsnote-head">
                            <span class="td-fsnote-label">Add internal note to FreshService</span>
                            <span v-if="fsNoteStatus" class="td-fsnote-status">{{ fsNoteStatus }}</span>
                            <button
                                type="button"
                                class="td-fsnote-add"
                                :disabled="!fsNote.trim() || fsNoteSaving"
                                @click="addFsNote"
                            >{{ fsNoteSaving ? 'Adding…' : 'Add note' }}</button>
                        </div>
                        <textarea
                            class="td-fsnote-text"
                            v-model="fsNote"
                            placeholder="Private note — added to the ticket in FreshService and shown here in the conversation. The customer never sees it."
                            rows="2"
                        ></textarea>
                    </section>

                    <!-- Metadata — tiered. Primary fields up top, soft-tier
                         (Domain/Category/Priority) as colour-coded badges,
                         technical fields tucked under a collapsible block. -->
                    <section v-if="metaPrimary.length" class="td-section td-section-meta td-tier-2">
                        <dl class="td-meta td-meta-primary">
                            <template v-for="m in metaPrimary" :key="m.key">
                                <dt>{{ m.key }}</dt>
                                <dd :title="m.utc || null">
                                    <a v-if="m.key === 'Ticket URL' && m.value" :href="m.value" target="_blank" rel="noopener">{{ m.value }}</a>
                                    <template v-else>{{ m.value }}</template>
                                </dd>
                            </template>
                        </dl>

                        <div v-if="metaSecondary.length" class="td-meta-secondary">
                            <span
                                v-for="m in metaSecondary"
                                :key="m.key"
                                :class="softMetaClass(m.key, m.value)"
                                :title="m.key + ': ' + m.value"
                            >{{ m.key }}: {{ m.value }}</span>
                        </div>

                        <div v-if="metaTechnical.length" class="td-meta-tech-wrap">
                            <button
                                type="button"
                                class="td-meta-tech-toggle"
                                @click="techMetaOpen = !techMetaOpen"
                                :aria-expanded="techMetaOpen ? 'true' : 'false'"
                            >
                                <span class="td-disclosure">{{ techMetaOpen ? '▾' : '▸' }}</span>
                                Technical details ({{ metaTechnical.length }})
                            </button>
                            <dl v-if="techMetaOpen" class="td-meta td-meta-tech">
                                <template v-for="m in metaTechnical" :key="m.key">
                                    <dt>{{ m.key }}</dt>
                                    <dd :title="m.utc || null">{{ m.value }}</dd>
                                </template>
                            </dl>
                        </div>

                        <!-- Timeline — collapsible right under Technical details. -->
                        <div v-if="data.timeline" class="td-meta-tech-wrap">
                            <button
                                type="button"
                                class="td-meta-tech-toggle"
                                @click="timelineOpen = !timelineOpen"
                                :aria-expanded="timelineOpen ? 'true' : 'false'"
                            >
                                <span class="td-disclosure">{{ timelineOpen ? '▾' : '▸' }}</span>
                                Timeline ({{ parsedTimelineLines.length }} entries)
                            </button>
                            <div v-if="timelineOpen" class="td-timeline-rich">
                                <div
                                    v-for="(line, i) in parsedTimelineLines"
                                    :key="i"
                                    class="td-timeline-row"
                                >
                                    <span v-if="line.date" class="td-timeline-date" :title="line.utc || null">{{ line.date }}</span>
                                    <span class="td-timeline-action">{{ line.action }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Reply draft button — hidden when draft is stale (auto-retry in progress
                         or rejected); the next clean draft will reappear here once it lands. -->
                    <section v-if="data.reply_draft && !draftIsStale" class="td-section">
                        <div class="td-draft-row">
                            <button
                                type="button"
                                class="btn td-draft-btn"
                                :class="draftAlreadySent ? 'btn-sent' : 'btn-primary'"
                                @click="sendModalOpen = true"
                            >
                                <template v-if="draftAlreadySent">✅ Reply sent — view</template>
                                <template v-else>📝 View reply draft</template>
                            </button>
            <!-- Passive indicator: auto-send was armed from the send modal.
                 Arming / cancelling happens inside that modal. -->
                            <span
                                v-if="!draftAlreadySent && data.reply_draft.auto_send_armed"
                                class="td-auto-send-badge"
                                title="This reply will send automatically once it passes Security Check. Open the reply draft to cancel."
                            >
                                <span class="td-auto-send-icon">⚡</span> Auto-send armed
                            </span>
                        </div>
                        <div v-if="draftAlreadySent" class="td-draft-meta">
                            Sent <span :title="toUtcLabel(data.reply_draft.sent_at) || null">{{ draftSentAt }}</span><template v-if="data.reply_draft.conversation_id"> &middot; FS conversation #{{ data.reply_draft.conversation_id }}</template>
                        </div>
                        <div v-else-if="draftCreatedAt" class="td-draft-meta" :title="toUtcLabel(data.reply_draft.created_at) || null">Created: {{ draftCreatedAt }}</div>
                    </section>

                    <!-- Stale-draft notice — surfaces the state so the reviewer understands
                         why the draft button is gone. -->
                    <section v-else-if="data.reply_draft && draftIsStale" class="td-section td-draft-stale">
                        <div class="td-draft-meta td-draft-meta-stale">
                            Previous draft was set aside (security check rewrite or reviewer rejection). A new draft is in progress — it will appear here once it passes Security Check.
                        </div>
                    </section>

                    <!-- (Workflow moved BETWEEN Initial message and Replies — see below.) -->

                    <!-- Subtle divider between the technical/supporting block above
                         and the conversation block below. Just a thin horizontal
                         rule with a small label — no heavy column split. -->
                    <div class="td-group-divider">
                        <span class="td-group-divider-label">Conversation</span>
                    </div>

                    <!-- Initial customer message — Subject + Body combined,
                         labelled as "Topic" and "Description" so the operator
                         instantly knows what each part is. No section H2. -->
                    <section v-if="data.subject || data.body || data.body_html" class="td-section td-tier-1 td-section-initial">
                        <template v-if="data.subject">
                            <div class="td-initial-label">Topic</div>
                            <div class="td-initial-subject">{{ data.subject }}</div>
                        </template>
                        <div v-if="data.body || data.body_html" class="td-initial-label">Description</div>
                        <div
                            v-if="data.body || data.body_html"
                            ref="initialBodyRef"
                            class="td-initial-body-wrap"
                            :class="{ 'td-initial-collapsed': initialCollapsible && !initialMessageExpanded }"
                        >
                            <div v-if="data.body_html" class="td-body-text td-html" v-html="withApiBase(data.body_html)"></div>
                            <div v-else class="td-body-text">
                                <template v-for="(seg, i) in renderSegments(data.body)" :key="i">
                                    <pre v-if="seg.type === 'text'" class="td-body-para">{{ seg.value }}</pre>
                                    <div v-else-if="seg.type === 'image'" class="td-body-imgrow">
                                        <a :href="attachmentUrl(seg.filename)" target="_blank" rel="noopener">
                                            <img :src="attachmentUrl(seg.filename)" :alt="seg.filename" class="td-inline-img" />
                                        </a>
                                    </div>
                                    <div v-else-if="seg.type === 'attachments'" class="td-attach-block">
                                        <div class="td-attach-label">Attachments:</div>
                                        <div class="td-attach-grid">
                                            <a v-for="(att, j) in seg.items" :key="j"
                                               :href="attachmentUrl(att.filename)"
                                               target="_blank" rel="noopener"
                                               class="td-attach-tile"
                                               :title="att.filename + (att.note ? ' — ' + att.note : '')"
                                            >
                                                <img v-if="isImage(att.filename)" :src="attachmentUrl(att.filename)" :alt="att.filename" />
                                                <span v-else class="td-attach-file">{{ att.filename }}</span>
                                            </a>
                                        </div>
                                    </div>
                                    <details v-else-if="seg.type === 'signature'" class="td-signature">
                                        <summary class="td-signature-summary">— signature —</summary>
                                        <template v-for="(sub, j) in seg.children" :key="j">
                                            <pre v-if="sub.type === 'text'" class="td-body-para">{{ sub.value }}</pre>
                                            <div v-else-if="sub.type === 'image'" class="td-body-imgrow">
                                                <a :href="attachmentUrl(sub.filename)" target="_blank" rel="noopener">
                                                    <img :src="attachmentUrl(sub.filename)" :alt="sub.filename" class="td-inline-img" />
                                                </a>
                                            </div>
                                        </template>
                                    </details>
                                </template>
                            </div>
                        </div>
                        <!-- Files the customer sent with the opening message.
                             Rendered from the ticket's `## Attachments` list, so a
                             real file (a PDF) is visible and downloadable — inline
                             images already appear in the body above and are marked
                             as such rather than offered twice as if they were new. -->
                        <div v-if="ticketAttachments.length" class="td-tatt">
                            <div class="td-tatt-label">
                                Attachments ({{ ticketAttachments.length }})
                            </div>
                            <ul class="td-tatt-list">
                                <li v-for="(a, i) in ticketAttachments" :key="i" class="td-tatt-item">
                                    <a v-if="a.downloadable"
                                       :href="attachmentUrl(a.filename)"
                                       target="_blank" rel="noopener"
                                       class="td-tatt-link"
                                       :title="'Open / download ' + a.filename"
                                    >
                                        <span class="td-tatt-icon">{{ attachmentIcon(a.filename) }}</span>
                                        <span class="td-tatt-name">{{ a.filename }}</span>
                                    </a>
                                    <span v-else class="td-tatt-link td-tatt-dead">
                                        <span class="td-tatt-icon">⚠</span>
                                        <span class="td-tatt-name">{{ a.filename }}</span>
                                    </span>
                                    <span v-if="a.inline" class="td-tatt-tag">shown in message</span>
                                    <span v-if="a.status === 'blocked'" class="td-tatt-tag td-tatt-tag-bad">
                                        blocked{{ a.note ? ': ' + a.note : '' }}
                                    </span>
                                    <span v-else-if="a.status === 'download_failed'" class="td-tatt-tag td-tatt-tag-bad">
                                        download failed
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <button
                            v-if="(data.body || data.body_html) && initialCollapsible"
                            type="button"
                            class="td-initial-toggle"
                            @click="initialMessageExpanded = !initialMessageExpanded"
                        >
                            {{ initialMessageExpanded ? '▴ Collapse description' : '▾ Show full description' }}
                        </button>
                    </section>

                    <!-- (Subtasks + Related Tasks moved above Initial message as
                         compact disclosures — see the "Workflow" section block.) -->

                    <!-- (Body merged into "Initial message" section above.) -->

                    <!-- Workflow — Subtasks + Related Tasks, sitting between the
                         initial message and the replies. Operator gets the
                         workflow context right where it's useful: just before
                         scanning the conversation. -->
                    <section v-if="(data.subtasks && data.subtasks.length) || (data.related_tasks && data.related_tasks.length)" class="td-section td-section-workflow">
                        <h2 class="td-h2">Workflow</h2>
                        <div v-if="data.subtasks && data.subtasks.length" class="td-meta-tech-wrap">
                            <button
                                type="button"
                                class="td-meta-tech-toggle"
                                @click="subtasksOpen = !subtasksOpen"
                                :aria-expanded="subtasksOpen ? 'true' : 'false'"
                            >
                                <span class="td-disclosure">{{ subtasksOpen ? '▾' : '▸' }}</span>
                                Subtasks ({{ data.subtasks.length }})
                            </button>
                            <ul v-if="subtasksOpen" class="td-subtasks">
                                <li v-for="(s, i) in data.subtasks" :key="i" class="td-subtask">
                                    <span class="td-checkbox">{{ s.checked ? '[x]' : '[ ]' }}</span>
                                    <a v-if="s.path" href="#" @click.prevent="openSubtask(s)" class="td-subtask-title">{{ s.title }}</a>
                                    <span v-else class="td-subtask-title">{{ s.title }}</span>
                                    <span v-if="s.kind" class="td-badge">{{ s.kind }}</span>
                                    <span
                                        v-if="subtaskBlockingBadge(s)"
                                        class="td-badge"
                                        :class="subtaskBlockingBadge(s).cls"
                                        :title="subtaskBlockingBadge(s).title"
                                    >{{ subtaskBlockingBadge(s).label }}</span>
                                    <span v-if="s.status && s.status !== 'done'" class="td-badge td-badge-status" :title="'status: ' + s.status">{{ s.status }}</span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="data.related_tasks && data.related_tasks.length" class="td-meta-tech-wrap">
                            <button
                                type="button"
                                class="td-meta-tech-toggle"
                                @click="relatedTasksOpen = !relatedTasksOpen"
                                :aria-expanded="relatedTasksOpen ? 'true' : 'false'"
                            >
                                <span class="td-disclosure">{{ relatedTasksOpen ? '▾' : '▸' }}</span>
                                Related tasks ({{ data.related_tasks.length }})
                            </button>
                            <ul v-if="relatedTasksOpen" class="td-related-tasks">
                                <li v-for="t in data.related_tasks" :key="t.task_id + '-' + t.list" class="td-related-task">
                                    <span class="td-checkbox">{{ t.checked ? '[x]' : '[ ]' }}</span>
                                    <span class="td-related-id">{{ t.task_id }}</span>
                                    <a v-if="t.task_id" href="#" @click.prevent="openRelated(t)" class="td-related-title td-related-title-link">{{ t.title }}</a>
                                    <span v-else class="td-related-title">{{ t.title }}</span>
                                    <span class="td-badge td-related-list" :title="t.list + ' — ' + t.section">
                                        <span class="td-related-list-name">{{ t.list }}</span>
                                        <span class="td-related-list-sep"> · </span>
                                        <span class="td-related-list-section">{{ t.section }}</span>
                                    </span>
                                    <span v-if="t.status_marker" class="td-badge td-badge-status">{{ t.status_marker }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- Replies -->
                    <section v-if="sortedReplies.length" class="td-section td-tier-1">
                        <h2 class="td-h2 td-h2-with-toggle">
                            <span>Replies ({{ sortedReplies.length }})</span>
                            <button
                                type="button"
                                class="td-sort-toggle"
                                :title="replySortDesc ? 'Newest first — click for oldest first' : 'Oldest first — click for newest first'"
                                @click="toggleReplySort"
                            >
                                <span class="td-sort-arrow">{{ replySortDesc ? '↓' : '↑' }}</span>
                                {{ replySortDesc ? 'Newest first' : 'Oldest first' }}
                            </button>
                            <button
                                v-if="internalMessageCount > 0"
                                type="button"
                                class="td-sort-toggle"
                                :class="{ 'td-sort-toggle-on': showInternalMessages }"
                                :title="showInternalMessages ? 'Internal messages (private notes, drafts, system events) are SHOWN — click to hide them' : 'Internal messages are HIDDEN — click to show them'"
                                @click="toggleInternalMessages"
                            >
                                <span>{{ showInternalMessages ? 'Internal: SHOWN' : 'Internal: hidden' }}</span>
                                <span class="td-toggle-count">{{ internalMessageCount }}</span>
                            </button>
                        </h2>
                        <article
                            v-for="r in sortedReplies"
                            :key="r.n"
                            class="td-reply"
                            :class="['td-reply-kind-' + replyKindSlug(r), replyClass(r)]"
                        >
                            <div class="td-reply-strip" :class="'td-reply-strip-' + replyKindSlug(r)">
                                <span class="td-reply-kind">{{ replyKindLabel(r) }}</span>
                                <span class="td-reply-index">#{{ r.n }}</span>
                            </div>
                            <div class="td-reply-header">
                                <div class="td-reply-author-line">
                                    <span class="td-reply-author">{{ r.author_name || r.author_email || 'Unknown sender' }}</span>
                                    <span v-if="r.author_email && r.author_name" class="td-reply-email">&lt;{{ r.author_email }}&gt;</span>
                                </div>
                                <div class="td-reply-meta-line">
                                    <span class="td-reply-timestamp" :title="toUtcLabel(r.timestamp) || null">{{ toLocalTime(r.timestamp) }}</span>
                                </div>
                            </div>
                            <div class="td-reply-body">
                                <div v-if="r.body_html" class="td-html" v-html="withApiBase(r.body_html)"></div>
                                <template v-else v-for="(seg, i) in renderSegments(r.body)" :key="i">
                                    <pre v-if="seg.type === 'text'" class="td-body-para">{{ seg.value }}</pre>
                                    <div v-else-if="seg.type === 'image'" class="td-reply-img-wrap">
                                        <a :href="attachmentUrl(seg.filename)" target="_blank" rel="noopener">
                                            <img :src="attachmentUrl(seg.filename)" :alt="seg.filename" class="td-inline-img" />
                                        </a>
                                    </div>
                                    <div v-else-if="seg.type === 'attachments'" class="td-attach-block">
                                        <div class="td-attach-label">Attachments:</div>
                                        <div class="td-attach-grid">
                                            <a v-for="(att, j) in seg.items" :key="j"
                                               :href="attachmentUrl(att.filename)"
                                               target="_blank" rel="noopener"
                                               class="td-attach-tile"
                                               :title="att.filename + (att.note ? ' — ' + att.note : '')"
                                            >
                                                <img v-if="isImage(att.filename)" :src="attachmentUrl(att.filename)" :alt="att.filename" />
                                                <span v-else class="td-attach-file">{{ att.filename }}</span>
                                            </a>
                                        </div>
                                    </div>
                                    <details v-else-if="seg.type === 'signature'" class="td-signature">
                                        <summary class="td-signature-summary">— signature —</summary>
                                        <template v-for="(sub, j) in seg.children" :key="j">
                                            <pre v-if="sub.type === 'text'" class="td-body-para">{{ sub.value }}</pre>
                                            <div v-else-if="sub.type === 'image'" class="td-reply-img-wrap">
                                                <a :href="attachmentUrl(sub.filename)" target="_blank" rel="noopener">
                                                    <img :src="attachmentUrl(sub.filename)" :alt="sub.filename" class="td-inline-img" />
                                                </a>
                                            </div>
                                        </template>
                                    </details>
                                </template>
                                <!-- Files the customer/agent attached to THIS reply.
                                     Shown regardless of the body_html vs markdown
                                     path above, so a screenshot on a follow-up reply
                                     is always visible (images inline, files as links). -->
                                <div v-if="r.attachments && r.attachments.length" class="td-attach-block td-reply-attach">
                                    <div class="td-attach-label">Attachments ({{ r.attachments.length }}):</div>
                                    <div class="td-attach-grid">
                                        <a v-for="(att, j) in r.attachments" :key="j"
                                           :href="attachmentUrl(att.filename)"
                                           target="_blank" rel="noopener"
                                           class="td-attach-tile"
                                           :title="'Open / download ' + att.filename"
                                        >
                                            <img v-if="isImage(att.filename)" :src="attachmentUrl(att.filename)" :alt="att.filename" />
                                            <span v-else class="td-attach-file">{{ att.filename }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </section>


                    <!-- (Timeline moved above Subject — see top of this template.) -->
                </template>
                <div v-else class="td-empty">No data.</div>
            </div>
        </aside>
    </div>
</template>

<script>
import TicketActions from './TicketActions.vue';
import RejectFeedbackModal from './RejectFeedbackModal.vue';
import ReplyDraftModal from './ReplyDraftModal.vue';
import ManualReplyModal from './ManualReplyModal.vue';
import SubtaskViewerModal from './SubtaskViewerModal.vue';
import ConfirmModal from './ConfirmModal.vue';
import KnowledgeFactModal from './KnowledgeFactModal.vue';
import SplitTicketModal from './SplitTicketModal.vue';
import modalStackMixin from '../modalStackMixin';

/**
 * TicketDetail — side panel that loads and displays a parsed ticket file.
 *
 * Props:
 *   - ticketId: String   — the ticket id (e.g. "T66050"). When this changes
 *     and isOpen is true, the panel re-fetches.
 *   - isOpen:   Boolean  — controls visibility. The panel emits `@close` when
 *     the user dismisses it (overlay click or close button); the parent is
 *     responsible for flipping isOpen back to false.
 *
 * Phase 2: action buttons just emit; we log them. Real modal handlers come
 * in Phase 3 / 4.
 */
export default {
    name: 'TicketDetail',
    mixins: [modalStackMixin],
    components: { TicketActions, RejectFeedbackModal, ReplyDraftModal, ManualReplyModal, SubtaskViewerModal, ConfirmModal, KnowledgeFactModal, SplitTicketModal },
    props: {
        ticketId: { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            loading: false,
            error: '',
            data: null,
            timelineOpen: false,
            rejectModalOpen: false,
            sendModalOpen: false,
            splitModalOpen: false,
            humanReviewModalOpen: false,
            closeModalOpen: false,
            automationFeedbackModalOpen: false,
            automationFeedbackBusy: false,
            knowledgeFactModalOpen: false,
            knowledgeFactBusy: false,
            replySortDesc: true, // default: newest reply at the top
            showInternalMessages: false, // default: HIDE everything except customer ↔ agent public replies
            // The opening message collapses only when BOTH hold:
            //   1. it is genuinely too long to fit (measured, see
            //      measureInitialOverflow), AND
            //   2. there is at least one reply to read below it.
            // With no replies the opening message IS the whole conversation —
            // there is nothing to scroll down to, so it is always shown in
            // full however long it is. `initialCollapsible` is that verdict;
            // when false there is no clipping, no fade and no toggle button.
            initialMessageExpanded: false,
            initialCollapsible: false,
            techMetaOpen: false, // technical metadata block is collapsed by default
            subtasksOpen: false, // subtasks disclosure is collapsed by default
            relatedTasksOpen: false, // related-tasks disclosure is collapsed by default
            starTick: 0, // bump to re-evaluate isStarred when the legacy table flips state
            labelTick: 0, // bump to re-evaluate ticketLabels when labels change elsewhere
            humanReviewBusy: false,
            closeBusy: false,
            statusModalOpen: false,
            statusModalTarget: '',  // 'open' | 'pending' | 'resolved' | 'closed'
            statusBusy: false,
            aiComposeModalOpen: false,
            aiComposeBusy: false,
            agentBannerRefreshTimer: null,
            ingesting: '',  // non-empty = backend is fetching the ticket from FS (202)
            operatorNote: '',
            operatorNoteDirty: false,
            operatorNoteSaving: false,
            operatorNoteStatus: '',
            fsNote: '',
            fsNoteSaving: false,
            fsNoteStatus: '',
            manualReplyModalOpen: false,
            subtaskModalOpen: false,
            subtaskModalFilename: '',
            subtaskModalTitle: '',
            relatedModalOpen: false,
            relatedModalUrl: '',
            relatedModalPath: '',
            relatedModalTitle: '',
            toastMessage: '',
            toastTimer: null,
        };
    },
    computed: {
        /** Files sent with the opening message (see `## Attachments`). */
        ticketAttachments() {
            return (this.data && this.data.attachments) || [];
        },
        // Every attachment across the whole conversation (opening message + all
        // replies), deduped by filename — the pool the split modal lets the
        // operator pick from to carry over to the new ticket.
        allConversationAttachments() {
            if (!this.data) return [];
            const seen = {};
            const out = [];
            const add = (list) => {
                for (const a of (list || [])) {
                    const f = a && a.filename;
                    if (f && !seen[f]) { seen[f] = true; out.push({ filename: f }); }
                }
            };
            add(this.data.attachments);
            for (const r of (this.data.replies || [])) add(r.attachments);
            return out;
        },
        automationFeedbackMessage() {
            return 'What should the automation have done differently? Refer to this ticket directly — triage reads the full context. The instruction change applies to every similar case going forward, not just this one. Goes through approval before anything is applied.';
        },
        automationFeedbackPlaceholder() {
            return "e.g. “The reply named a UI control that doesn’t exist on that screen — should have flagged a gap, not invented one.”";
        },
        statusModalLabel() {
            const t = this.statusModalTarget || '';
            return t ? (t.charAt(0).toUpperCase() + t.slice(1)) : '';
        },
        statusModalTitle() {
            return this.statusModalLabel
                ? 'Set ticket status to ' + this.statusModalLabel + ' on FreshService'
                : 'Set ticket status';
        },
        statusModalMessage() {
            if (!this.statusModalLabel) return '';
            const id = this.ticketId;
            if (this.statusModalTarget === 'closed') {
                return 'This will set ticket ' + id + ' to Closed on FreshService and move it to the Closed section. The customer will see the ticket as closed. This action cannot be undone from the dashboard.';
            }
            return 'This will set ticket ' + id + ' to ' + this.statusModalLabel + ' on FreshService. The pipeline section on our task list is unchanged.';
        },
        statusModalConfirmLabel() {
            return this.statusModalLabel ? ('Set to ' + this.statusModalLabel) : 'Set status';
        },
        statusModalConfirmVariant() {
            return this.statusModalTarget === 'closed' ? 'danger' : 'primary';
        },
        aiComposeMessage() {
            return 'Tell the agent what to do for this ticket. It will read the full ticket context (including subtasks and prior replies) and follow your instruction. Common asks: draft a reply with a specific framing, create a follow-on subtask (data-lookup, consult-developer, ask-requester, …), look up a tenant-side fact, or any combination. The result lands in the ticket’s pipeline (draft → Security Check, subtasks where they belong).';
        },
        aiComposePlaceholder() {
            return "e.g. “Draft a reply confirming we received the file and explaining we’ll process it within 24h; also create an ask-requester subtask to get the order id.”";
        },

        /* Banner showing the live status of a Manual Agent Request (the
         * dashboard's "Ask agent" action). Null when there's no active
         * request on the ticket. The status is inferred from the
         * `Manual Agent Request` metadata value + the latest Timeline
         * entry that mentions it. Active phases auto-refresh — see the
         * watcher below. */
        agentRequestBanner() {
            if (!this.data || !this.data.metadata) return null;
            const raw = String(this.data.metadata['Manual Agent Request'] || '').trim();
            if (!raw) return null;

            // Parse the "<instruction> — added <ts> via dashboard" shape.
            // We use a permissive split so the banner still works if the
            // suffix is missing (e.g. operator hand-edited the line).
            let instruction = raw;
            let addedAt = '';
            const m = raw.match(/^(.+?)\s+—\s+added\s+(\S+\s+\S+\s+\S+)\s+via\s+dashboard\s*$/);
            if (m) {
                instruction = m[1].trim();
                addedAt = m[2].trim();
            }

            // Classify by the leading marker, if any.
            let label = 'Agent working…';
            let tone = 'in-flight';
            let canRefresh = true;
            if (instruction.startsWith('_(addressed')) {
                label = 'Done';
                tone = 'done';
                canRefresh = false;
            } else if (instruction.startsWith('_(handled — drafting')
                    || instruction.startsWith('_(handled - drafting')) {
                label = 'Drafting reply…';
                tone = 'in-flight';
            } else if (instruction.startsWith('_(unclear')) {
                label = 'Unclear — needs clarification';
                tone = 'error';
                canRefresh = false;
            } else if (instruction.startsWith('_(none')) {
                return null;  // explicit nothing
            } else {
                label = 'Queued — waiting for orchestrator';
                tone = 'queued';
            }

            // Find the latest Timeline entry that's about this request.
            // Pattern matches both `dashboard - Manual Agent Request queued`
            // and `orchestrate - Manual Agent Request executed …`.
            let lastActivity = '';
            const timeline = String(this.data.timeline || '');
            const tlLines = timeline.split('\n').filter(Boolean);
            for (let i = tlLines.length - 1; i >= 0; i--) {
                const line = tlLines[i];
                if (line.includes('Manual Agent Request')) {
                    lastActivity = this.localiseStamps(line.replace(/^\s*-\s*/, '').trim());
                    break;
                }
            }

            // Elapsed since the request was added (only show while in flight).
            let elapsed = '';
            if (addedAt && tone !== 'done' && tone !== 'error') {
                const parsed = Date.parse(addedAt.replace(' UTC', 'Z').replace(/\s+/, 'T'));
                if (!isNaN(parsed)) {
                    const seconds = Math.max(0, Math.floor((Date.now() - parsed) / 1000));
                    if (seconds < 60) {
                        elapsed = seconds + 's ago';
                    } else if (seconds < 3600) {
                        elapsed = Math.floor(seconds / 60) + 'm ago';
                    } else {
                        elapsed = Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm ago';
                    }
                }
            }

            const fullInstruction = m ? m[1].trim() : raw;
            return {
                label,
                tone,
                canRefresh,
                elapsed,
                instruction: fullInstruction.length > 240
                    ? fullInstruction.slice(0, 240) + '…'
                    : fullInstruction,
                lastActivity,
            };
        },
        /* True while the banner is in an active phase (queued / drafting),
         * which is when we want to auto-refresh. Done / error don't poll. */
        agentRequestInFlight() {
            const b = this.agentRequestBanner;
            return !!(b && (b.tone === 'queued' || b.tone === 'in-flight'));
        },
        metaEntries() {
            if (!this.data || !this.data.metadata) return [];
            return Object.entries(this.data.metadata)
                .filter(([key]) => key !== 'Reply Draft')
                .map(([key, value]) => ({ key, value }));
        },

        /* Three-tier metadata grouping for the detail header.
           Empty / placeholder values ("_(to be filled by grading)_", "—", "-")
           are hidden so the panel doesn't waste vertical space on noise. */
        metaPrimary() {
            return this.pickMeta(['Client', 'Requester', 'CC', 'Status', 'Received', 'Created Date', 'Ticket URL']);
        },
        metaSecondary() {
            return this.pickMeta(['Domain', 'Category', 'Priority']);
        },
        metaTechnical() {
            const handled = new Set([
                'Reply Draft', // explicit exclusion (rendered elsewhere as a CTA)
                'Client', 'Requester', 'CC', 'Status', 'Received', 'Created Date', 'Ticket URL',
                'Domain', 'Category', 'Priority',
            ]);
            if (!this.data || !this.data.metadata) return [];
            return Object.entries(this.data.metadata)
                .filter(([k, v]) => !handled.has(k) && this.metaValueIsMeaningful(v))
                .map(([key, value]) => ({ key, value: this.formatMetaValue(key, value) }));
        },

        /* Timeline lines split into { date, action } so the date can be
           rendered in its own colour (yellow-amber) instead of blending
           into the action text. Handles all Timeline line shapes the
           pipeline writes:
             - `- 2026-06-11T06:58:03Z - Ticket received from FreshService`
             - `- **2026-06-11 07:01 UTC** artur - Moved to ## Decomposition`
             - `- 2026-06-11 15:42 UTC: Security Check pass.`
             - `- 2026-06-12 12:00 UTC robert - Reset for full reprocessing.`
           Lines that don't match any date pattern still render with empty
           date and the full action — we never drop anything. */
        parsedTimelineLines() {
            const raw = (this.data && this.data.timeline) || '';
            if (!raw) return [];
            const out = [];
            for (const rawLine of String(raw).split(/\r?\n/)) {
                let line = rawLine.replace(/^[\-\s]+/, '').trim();
                if (!line) continue;
                // Strip leading `**` ... `**` wrappers so the date itself can
                // be lifted out cleanly.
                let m = line.match(/^\*\*(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\s*UTC)?Z?)\*\*\s*[-:\s]*(.*)$/);
                if (m) { out.push(this.timelineEntry(m[1], m[2])); continue; }
                m = line.match(/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\s*UTC)?Z?)\s*[-:\s]+(.*)$/);
                if (m) { out.push(this.timelineEntry(m[1], m[2])); continue; }
                out.push({ date: '', utc: '', action: line });
            }
            return out;
        },

        isStarred() {
            // `this.starTick` is read so Vue re-evaluates when toggled externally.
            // eslint-disable-next-line no-unused-expressions
            this.starTick;
            const id = String(this.ticketId || '').replace(/^T/i, '');
            return typeof window.isTicketStarred === 'function' ? window.isTicketStarred(id) : false;
        },

        // Numeric ticket id (labels key on the FreshService number, no "T").
        numericId() {
            return String(this.ticketId || '').replace(/^T/i, '');
        },

        // Shared labels currently assigned to this ticket. `labelTick` is read
        // so Vue re-evaluates when the assignment changes from the popover.
        ticketLabels() {
            // eslint-disable-next-line no-unused-expressions
            this.labelTick;
            const all = typeof window.getAllLabels === 'function' ? window.getAllLabels() : [];
            const byId = {};
            all.forEach(l => { byId[l.id] = l; });
            const ids = typeof window.getTicketLabelIds === 'function' ? window.getTicketLabelIds(this.numericId) : [];
            return ids.map(id => byId[id]).filter(Boolean);
        },

        /* Replies sorted + filtered by user preferences.
           Sort: newest first by default; toggleable.
           Filter: by default ONLY the customer ↔ agent public conversation
           is shown. Internal stuff (private notes, draft proposals, system
           events) is HIDDEN until the operator toggles "show internal too". */
        sortedReplies() {
            if (!this.data || !this.data.replies) return [];
            let arr = this.data.replies.slice();
            if (!this.showInternalMessages) {
                arr = arr.filter(r => this.isPublicConversation(r));
            }
            const dir = this.replySortDesc ? -1 : 1;
            arr.sort((a, b) => {
                const ta = this.parseReplyTimestamp(a.timestamp) || a.n || 0;
                const tb = this.parseReplyTimestamp(b.timestamp) || b.n || 0;
                if (ta === tb) return ((a.n || 0) - (b.n || 0)) * dir;
                return (ta - tb) * dir;
            });
            return arr;
        },
        internalMessageCount() {
            if (!this.data || !this.data.replies) return 0;
            return this.data.replies.filter(r => !this.isPublicConversation(r)).length;
        },
        draftAlreadySent() {
            return !!(this.data && this.data.reply_draft && this.data.reply_draft.sent_at);
        },
        draftIsStale() {
            // The parent ticket's `**Reply Draft:**` metadata is set to a
            // string containing "stale" by the Reject + Feedback action and by
            // the Security Check auto-retry path when the current draft is
            // superseded. Use that as the canonical "don't show this draft"
            // signal.
            if (!this.data || !this.data.metadata) return false;
            const draftMeta = String(this.data.metadata['Reply Draft'] || '');
            return draftMeta.toLowerCase().includes('stale');
        },
        draftSentAt() {
            const raw = this.data && this.data.reply_draft ? this.data.reply_draft.sent_at : null;
            return raw ? this.toLocalTime(raw) : '';
        },
        draftCreatedAt() {
            const iso = this.data && this.data.reply_draft ? this.data.reply_draft.created_at : null;
            return this.formatDraftDate(iso);
        },
        shortPath() {
            if (!this.data || !this.data.path) return '';
            const p = this.data.path;
            const idx = p.lastIndexOf('/');
            return idx >= 0 ? p.substring(idx + 1) : p;
        },
        pipelineStageClass() {
            const s = this.data && this.data.pipeline_section ? this.data.pipeline_section : '';
            const slug = s.toLowerCase().replace(/[^a-z]+/g, '-').replace(/(^-|-$)/g, '');
            return slug ? 'td-stage-' + slug : '';
        },
        manualReplyToEmail() {
            const requester = this.data && this.data.metadata ? this.data.metadata['Requester'] || '' : '';
            return this.extractFirstEmail(requester);
        },
        manualReplyCcEmails() {
            const cc = this.data && this.data.metadata ? this.data.metadata['CC'] || '' : '';
            return this.extractAllEmails(cc);
        },
        manualReplySubject() {
            return (this.data && this.data.subject) ? String(this.data.subject).trim() : '';
        },
    },
    watch: {
        ticketId(newId) {
            if (newId && this.isOpen) this.load();
            this.syncAgentBannerTimer();
        },
        isOpen(open) {
            if (open && this.ticketId) this.load();
            this.syncAgentBannerTimer();
        },
        /* Start polling for fresh ticket state while a Manual Agent Request
         * is in flight (queued / drafting). Stop as soon as it lands in a
         * terminal state (done / error) or the panel closes. */
        agentRequestInFlight() {
            this.syncAgentBannerTimer();
        },
    },
    mounted() {
        if (this.isOpen && this.ticketId) this.load();
        // Esc is handled centrally by window.ModalStack (via modalStackMixin) so
        // only the frontmost modal closes — no per-panel Esc handler here.
        // The legacy table's star-toggle handler notifies us so the header
        // icon stays in sync if the user toggles from the table while the
        // panel is open.
        window.onTicketStarChanged = (id) => {
            const myId = String(this.ticketId || '').replace(/^T/i, '');
            if (String(id) === myId) this.starTick++;
        };
        // Refresh label chips when the shared list / assignment changes anywhere.
        window.onTicketLabelsChanged = () => { this.labelTick++; };
    },
    beforeUnmount() {
        if (window.onTicketStarChanged) window.onTicketStarChanged = null;
        if (window.onTicketLabelsChanged) window.onTicketLabelsChanged = null;
        this.stopAgentBannerTimer();
        clearTimeout(this._ingestRetry);
    },
    methods: {
        /* --- metadata helpers ---------------------------------------------- */
        metaValueIsMeaningful(v) {
            if (v === null || v === undefined) return false;
            const s = String(v).trim();
            if (s === '' || s === '—' || s === '-' || s === 'N/A') return false;
            // Drafter-style placeholders the agents write for unfilled fields.
            if (/_\(to be filled by grading\)_/i.test(s)) return false;
            if (/_\(none recorded\)_/i.test(s)) return false;
            if (/_\(none\)_/i.test(s)) return false;
            if (/_\(unknown\)_/i.test(s)) return false;
            if (/_\(reset on pass\)_/i.test(s)) return false;
            if (/_\(addressed in this draft.*\)_/i.test(s)) return false;
            return true;
        },
        pickMeta(keys) {
            if (!this.data || !this.data.metadata) return [];
            return keys
                .map(k => ({
                    key: k,
                    value: this.formatMetaValue(k, this.data.metadata[k]),
                    utc: this.utcTooltip(k, this.data.metadata[k]),
                }))
                .filter(m => this.metaValueIsMeaningful(m.value));
        },
        /* Render date-like metadata in the VIEWER's own time zone, so everyone
           reads a time that means something where they are. Non-date keys and
           unparseable strings pass through untouched. The stored UTC value is
           kept alongside as the hover tooltip — see `utcTooltip`. */
        formatMetaValue(key, value) {
            if (value === null || value === undefined) return value;
            if (!this.isDateKey(key)) return value;
            const s = String(value).trim();
            if (!s) return s;
            return this.toLocalTime(s);
        },
        /* The stored UTC value, shown on hover so it can still be compared
           against FreshService and the logs. Empty for non-date fields. */
        utcTooltip(key, value) {
            if (!this.isDateKey(key) || value === null || value === undefined) return '';
            const s = String(value).trim();
            return s ? this.toUtcLabel(s) : '';
        },
        isDateKey(key) {
            return ['Received', 'Created Date', 'Updated', 'Resolved At', 'Closed At']
                .includes(key);
        },
        /* Time-zone rendering lives in one place — public/js/local-time.js,
           shared with the SPA shell. If that script is somehow missing, show
           the stored value unchanged rather than a broken or, worse, silently
           wrong time. */
        toLocalTime(value) {
            const L = typeof window !== 'undefined' ? window.LocalTime : null;
            return L ? L.format(value) : value;
        },
        toUtcLabel(value) {
            const L = typeof window !== 'undefined' ? window.LocalTime : null;
            return L ? L.utc(value) : '';
        },
        /* Convert every UTC stamp embedded in a free-text line (a raw Timeline
           entry shown inside a sentence) to the viewer's zone, leaving the rest
           of the wording untouched. */
        localiseStamps(text) {
            if (!text) return text;
            const L = typeof window !== 'undefined' ? window.LocalTime : null;
            if (!L) return text;
            return String(text).replace(
                /\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?\s*(?:UTC|Z)/gi,
                (stamp) => L.format(stamp)
            );
        },
        /* One timeline row: the stamp shown in the viewer's zone, the stored
           UTC kept for the hover tooltip, and the action text. */
        timelineEntry(rawDate, action) {
            const stamp = String(rawDate).replace(/\s+/g, ' ').trim();
            return {
                date: this.toLocalTime(stamp),
                utc: this.toUtcLabel(stamp),
                action: String(action).trim(),
            };
        },
        /* Color-coded badge for the soft-tier fields. Falls back to a neutral
           tint when the value doesn't match a known bucket. */
        softMetaClass(key, value) {
            const v = String(value || '').toLowerCase();
            if (key === 'Priority') {
                if (/urgent|critical|p0/.test(v)) return 'td-badge td-badge-red';
                if (/high|p1/.test(v))            return 'td-badge td-badge-orange';
                if (/medium|p2/.test(v))          return 'td-badge td-badge-yellow';
                if (/low|p3|p4/.test(v))          return 'td-badge td-badge-green';
                return 'td-badge td-badge-neutral';
            }
            if (key === 'Domain') {
                // Stable per-value hue: hash the string into one of the named buckets.
                return 'td-badge ' + this.stableBadgeBucket(v);
            }
            if (key === 'Category') {
                return 'td-badge td-badge-soft';
            }
            return 'td-badge td-badge-neutral';
        },
        stableBadgeBucket(s) {
            const buckets = ['td-badge-purple', 'td-badge-teal', 'td-badge-blue', 'td-badge-pink', 'td-badge-amber'];
            let h = 0;
            for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
            return buckets[Math.abs(h) % buckets.length];
        },

        /* --- star helpers (shared localStorage state with the legacy SPA) ---*/
        onToggleStar() {
            const id = String(this.ticketId || '').replace(/^T/i, '');
            if (typeof window.toggleTicketStar === 'function') {
                window.toggleTicketStar(id);
            }
            this.starTick++; // force the computed to re-read
        },

        // Open the shared assign-labels popover (same one the table row uses),
        // anchored to the panel's "+ Labels" button.
        openLabels(event) {
            if (typeof window.openLabelPicker === 'function') {
                window.openLabelPicker(event, this.numericId);
            }
        },

        /* --- reply sorting helpers ----------------------------------------- */
        parseReplyTimestamp(ts) {
            if (!ts) return 0;
            // Replies typically look like "2026-06-12 09:30 UTC" or ISO 8601.
            const s = String(ts).trim();
            // Normalise "YYYY-MM-DD HH:MM UTC" to ISO-friendly.
            let iso = s.replace(/\s+UTC$/i, 'Z').replace(/\s+/, 'T');
            const d = new Date(iso);
            const ms = d.getTime();
            return isNaN(ms) ? 0 : ms;
        },
        toggleReplySort() {
            this.replySortDesc = !this.replySortDesc;
        },
        toggleInternalMessages() {
            this.showInternalMessages = !this.showInternalMessages;
        },
        /* "Public conversation" = the messages the customer actually sees on
           FS — their own posts + agent public replies. Everything else
           (private notes, draft proposals, system-emitted events) is INTERNAL
           and hidden by default; the operator opts in to see it. */
        isPublicConversation(r) {
            const t = String((r && r.type) || '').toLowerCase();
            const role = String((r && r.role) || '').toLowerCase();
            if (role.includes('requester') || role.includes('customer')) return true;
            if (t.includes('public') && role.includes('agent')) return true;
            return false;
        },
        /* Human-readable reply-type label shown in the colored top-strip.
           The operator should know at a glance whether each reply is a
           customer message, a sent reply, a private note, or a draft. */
        replyKindLabel(r) {
            const t = String((r && r.type) || '').toLowerCase();
            const role = String((r && r.role) || '').toLowerCase();
            if (t.includes('draft')) return 'Draft proposal (not sent)';
            if (t.includes('private')) return 'Private note (internal)';
            if (t.includes('public') && role.includes('agent')) return 'Public reply (sent to customer)';
            if (role.includes('requester') || role.includes('customer')) return 'Customer message';
            if (role) return role.charAt(0).toUpperCase() + role.slice(1) + (t ? ' — ' + t : '');
            return t ? t.charAt(0).toUpperCase() + t.slice(1) : 'Message';
        },
        replyKindSlug(r) {
            const t = String((r && r.type) || '').toLowerCase();
            const role = String((r && r.role) || '').toLowerCase();
            if (t.includes('draft')) return 'draft';
            if (t.includes('private')) return 'private';
            if (t.includes('public') && role.includes('agent')) return 'public';
            if (role.includes('requester') || role.includes('customer')) return 'customer';
            return 'default';
        },

        async load({ silent = false } = {}) {
            // `silent: true` is used by the agent-banner auto-refresh and
            // any other background poller: it swaps the data in place
            // without blanking the panel, without toggling the loading
            // spinner, and without resetting operator-controlled UI state
            // (e.g. `initialMessageExpanded`, scroll position). Without
            // this, polling every 5 s caused the whole detail panel to
            // flash blank repeatedly.
            if (!silent) {
                this.loading = true;
                this.error = '';
                this.data = null;
            }
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/detail');
                // 202 = the ticket wasn't in the pipeline yet and the backend
                // is ingesting it from FreshService. Show a friendly
                // "fetching…" state and auto-retry shortly instead of erroring.
                if (resp.status === 202) {
                    let msg = 'Fetching this ticket from FreshService…';
                    try { msg = (await resp.json()).message || msg; } catch (_) {}
                    if (!silent) {
                        this.error = '';
                        this.ingesting = msg;
                    }
                    // Auto-retry once the backend has had time to write the file.
                    clearTimeout(this._ingestRetry);
                    this._ingestRetry = setTimeout(() => {
                        if (this.isOpen && this.ticketId) this.load({ silent: true });
                    }, 4000);
                    return;
                }
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                const fresh = await resp.json();
                this.ingesting = '';
                this.data = fresh;
                // Sync the editable operator note from the file — but never
                // clobber an in-progress edit (dirty) or stomp on it during a
                // background refresh. Treat the `_(empty)_` placeholder as blank.
                if (!this.operatorNoteDirty) {
                    const raw = (fresh && fresh.operator_note) || '';
                    this.operatorNote = raw.trim() === '_(empty)_' ? '' : raw;
                    // Size the box to the loaded note, not just to typing.
                    this.$nextTick(() => this.autosizeOperatorNote());
                }
                if (!silent) {
                    // First / interactive load: decide whether the opening
                    // message needs collapsing at all. Background refreshes
                    // leave the operator's current choice alone.
                    //
                    // Anything that fits is shown in full — see
                    // measureInitialOverflow, which clips and measures itself.
                    this.initialMessageExpanded = false;
                    this.$nextTick(() => {
                        this.measureInitialOverflow();
                        this.remeasureWhenImagesLoad();
                    });
                }
            } catch (e) {
                if (!silent) {
                    this.error = e.message || String(e);
                }
                // Silent failures are intentional: a flaky background poll
                // shouldn't blow away a usable panel. The next tick will
                // try again.
            } finally {
                if (!silent) {
                    this.loading = false;
                }
            }
        },
        close() {
            this.$emit('close');
        },
        onAction(key) {
            if (key === 'reject-draft')  { this.rejectModalOpen = true; return; }
            if (key === 'send-reply')    { this.sendModalOpen = true; return; }
            if (key === 'human-review')  { this.humanReviewModalOpen = true; return; }
            if (key === 'close')         { this.statusModalTarget = 'closed'; this.statusModalOpen = true; return; }
            if (key.startsWith('set-status:')) {
                this.statusModalTarget = key.split(':')[1];
                this.statusModalOpen = true;
                return;
            }
            if (key === 'ai-compose')    { this.aiComposeModalOpen = true; return; }
            if (key === 'compose-reply') { this.manualReplyModalOpen = true; return; }
            if (key === 'split')         { this.splitModalOpen = true; return; }
            if (key === 'report-automation') { this.automationFeedbackModalOpen = true; return; }
            if (key === 'add-knowledge-fact') { this.knowledgeFactModalOpen = true; return; }
            console.log('[TicketDetail] action:', key, 'ticket:', this.ticketId);
        },
        async onAutomationFeedbackConfirm({ reason }) {
            this.automationFeedbackBusy = true;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/report-automation', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ reason: reason || '' }),
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                await resp.json();
                this.showToast('Automation feedback saved for review');
                this.automationFeedbackModalOpen = false;
                this.load();
            } catch (e) {
                this.showToast('Failed: ' + (e.message || e));
            } finally {
                this.automationFeedbackBusy = false;
            }
        },
        async onKnowledgeFactConfirm({ fact }) {
            this.knowledgeFactBusy = true;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/add-knowledge-fact', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ fact }),
                });
                if (!resp.ok) {
                    let detail = '';
                    try { detail = (await resp.json()).error || ''; } catch (_) {}
                    throw new Error('HTTP ' + resp.status + (detail ? ' (' + detail + ')' : ''));
                }
                await resp.json();
                this.showToast('Knowledge fact saved — triage decides where it slots');
                this.knowledgeFactModalOpen = false;
                this.load();
            } catch (e) {
                this.showToast('Failed: ' + (e.message || e));
            } finally {
                this.knowledgeFactBusy = false;
            }
        },
        async onHumanReviewConfirm({ reason }) {
            this.humanReviewBusy = true;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/human-review', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ reason: reason || '' }),
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                this.showToast(data.status === 'already_in_human_review'
                    ? 'Already in Human Review'
                    : 'Moved to Human Review');
                this.humanReviewModalOpen = false;
                this.load();
            } catch (e) {
                this.showToast('Failed: ' + (e.message || e));
            } finally {
                this.humanReviewBusy = false;
            }
        },
        /* Auto-refresh logic for the Manual Agent Request banner. We poll
         * the ticket every 5 s while the request is in flight so the
         * operator sees the status change from Queued → Drafting → Done
         * without having to refresh manually. The timer only runs while
         * the panel is open AND the banner is in an active phase. */
        syncAgentBannerTimer() {
            if (this.isOpen && this.agentRequestInFlight) {
                this.startAgentBannerTimer();
            } else {
                this.stopAgentBannerTimer();
            }
        },
        startAgentBannerTimer() {
            if (this.agentBannerRefreshTimer) return;
            this.agentBannerRefreshTimer = setInterval(() => {
                if (!this.isOpen || !this.ticketId) {
                    this.stopAgentBannerTimer();
                    return;
                }
                this.load({ silent: true });
            }, 5000);
        },
        stopAgentBannerTimer() {
            if (this.agentBannerRefreshTimer) {
                clearInterval(this.agentBannerRefreshTimer);
                this.agentBannerRefreshTimer = null;
            }
        },
        async saveOperatorNote() {
            this.operatorNoteSaving = true;
            this.operatorNoteStatus = '';
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/operator-note', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ note: this.operatorNote || '' }),
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                await resp.json();
                this.operatorNoteDirty = false;
                this.operatorNoteStatus = 'Saved';
                setTimeout(() => { this.operatorNoteStatus = ''; }, 2500);
            } catch (e) {
                this.operatorNoteStatus = 'Failed: ' + (e.message || e);
            } finally {
                this.operatorNoteSaving = false;
            }
        },
        async addFsNote() {
            const note = (this.fsNote || '').trim();
            if (!note) return;
            this.fsNoteSaving = true;
            this.fsNoteStatus = '';
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/internal-note', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ note }),
                });
                if (!resp.ok) {
                    let detail = '';
                    try {
                        const errBody = await resp.json();
                        detail = errBody.message || errBody.error || '';
                    } catch (_) { /* ignore */ }
                    throw new Error(detail || ('HTTP ' + resp.status));
                }
                await resp.json();
                this.fsNote = '';
                this.fsNoteStatus = 'Added';
                setTimeout(() => { this.fsNoteStatus = ''; }, 2500);
                // Refresh the conversation stream so the new note shows.
                this.load({ silent: true });
            } catch (e) {
                this.fsNoteStatus = 'Failed: ' + (e.message || e);
            } finally {
                this.fsNoteSaving = false;
            }
        },
        async onAiComposeConfirm({ reason }) {
            this.aiComposeBusy = true;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/ai-compose', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ instructions: reason || '' }),
                });
                if (!resp.ok) {
                    // Surface the backend's error message (e.g. "ticket not on
                    // production list") so the operator sees WHY the request
                    // was rejected, not just an opaque HTTP code.
                    let detail = '';
                    try {
                        const errBody = await resp.json();
                        detail = errBody.message || errBody.error || '';
                    } catch (_) { /* non-JSON body — ignore */ }
                    throw new Error(detail || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                if (data.status === 'already_queued') {
                    this.showToast('An agent request is already in flight for this ticket');
                } else {
                    this.showToast('Sent to agent — result will appear in the pipeline');
                }
                this.aiComposeModalOpen = false;
                this.load();
            } catch (e) {
                this.showToast('Failed: ' + (e.message || e));
            } finally {
                this.aiComposeBusy = false;
            }
        },
        async onStatusConfirm() {
            if (!this.statusModalTarget) return;
            this.statusBusy = true;
            const targetLabel = this.statusModalLabel;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        status: this.statusModalTarget,
                    }),
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                if (data.status === 'already_set') {
                    this.showToast('Ticket already ' + targetLabel + ' on FreshService');
                } else {
                    this.showToast('Ticket status set to ' + targetLabel + ' on FreshService');
                }
                this.statusModalOpen = false;
                this.load();
            } catch (e) {
                this.showToast('Failed: ' + (e.message || e));
            } finally {
                this.statusBusy = false;
            }
        },
        /**
         * Grow the internal-note box to fit its content.
         *
         * It used to be a fixed 3 rows, so the common case — a one-line note
         * like "waiting for X to be released" — sat marooned at the top of an
         * empty box, which is what made it hard to read. Now the box is as tall
         * as the note, and grows as you type. The user can still drag-resize.
         */
        /** Pick a glyph by file type so the list scans without reading names. */
        attachmentIcon(filename) {
            const ext = String(filename || '').split('.').pop().toLowerCase();
            if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return '🖼';
            if (ext === 'pdf') return '📕';
            if (['xls', 'xlsx', 'csv'].includes(ext)) return '📊';
            if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) return '📄';
            if (['zip', 'rar', '7z', 'gz', 'tar'].includes(ext)) return '🗜';
            if (['txt', 'log'].includes(ext)) return '📃';
            return '📎';
        },
        autosizeOperatorNote() {
            const el = this.$refs.opNoteRef;
            if (!el) return;
            el.style.height = 'auto';           // shrink first, so deleting text shrinks the box too
            el.style.height = el.scrollHeight + 'px';
        },
        /**
         * Decide whether the opening message should be collapsed. BOTH must
         * hold — either one alone is not enough:
         *
         *   1. There is at least one reply shown below it. With no replies the
         *      opening message is the entire conversation, so there is nothing
         *      to reveal by hiding it — it stays open at any length. Counted
         *      from sortedReplies (what's actually rendered), not the raw list,
         *      which is mostly internal notes / draft proposals the Replies
         *      section hides.
         *   2. It is genuinely too long to fit. Measured, never guessed:
         *      scrollHeight (full content) vs clientHeight (the clipped box).
         *      That keeps the threshold in the CSS
         *      (`.td-initial-collapsed { max-height }`) as the single source of
         *      truth — no duplicated line/char count in JS to drift from it.
         *
         * The measurement is only meaningful while the element is clipped —
         * unclipped, scrollHeight === clientHeight and everything looks like it
         * fits. So we re-clip first and measure on the next tick; that also
         * keeps a re-measure (after images load) correct even when a previous
         * pass concluded "fits" and dropped the clipping.
         */
        measureInitialOverflow() {
            if (this.initialMessageExpanded) return; // operator's choice wins
            if (!this.sortedReplies.length) {
                this.initialCollapsible = false;     // rule 1 — never collapses
                return;
            }
            this.initialCollapsible = true;          // clip, so rule 2 is measurable
            this.$nextTick(() => {
                const el = this.$refs.initialBodyRef;
                if (!el) {
                    this.initialCollapsible = false;
                    return;
                }
                // 2px tolerance: sub-pixel line-height rounding otherwise
                // reports a 1px "overflow" on text that visually fits exactly.
                this.initialCollapsible = el.scrollHeight > el.clientHeight + 2;
            });
        },
        /**
         * Inline images (customer screenshots) have zero height until they
         * load, so a first measurement can wrongly conclude "it fits". Re-run
         * once each one lands.
         */
        remeasureWhenImagesLoad() {
            const el = this.$refs.initialBodyRef;
            if (!el) return;
            el.querySelectorAll('img').forEach((img) => {
                if (img.complete) return;
                img.addEventListener('load', () => {
                    // Only meaningful while still clipped — an operator who has
                    // already expanded it must not be collapsed out from under.
                    if (!this.initialMessageExpanded) this.measureInitialOverflow();
                }, { once: true });
            });
        },
        onReplySent(payload) {
            const cid = payload && payload.conversation_id ? payload.conversation_id : '';
            this.showToast(cid ? ('Reply sent (FS #' + cid + ')') : 'Reply sent');
            this.sendModalOpen = false;
            this.load();
        },
        onSplit(payload) {
            const nid = payload && payload.new_ticket_id ? payload.new_ticket_id : '';
            this.showToast(nid ? ('Split done — new ticket #' + nid + ' created') : 'Split done');
            // Keep the modal open on its success state so the operator can click
            // through to the new FS ticket; reload this ticket for the new
            // Timeline entry. The modal's own Close button dismisses it.
            this.load({ silent: true });
        },
        onAutoSendArmed(payload) {
            const armed = !!(payload && payload.armed);
            if (this.data && this.data.reply_draft) {
                this.data.reply_draft.auto_send_armed = armed;
            }
            if (armed) {
                this.showToast('Auto-send armed — this reply will send once it passes Security Check');
                this.sendModalOpen = false;
            } else {
                this.showToast('Auto-send cancelled');
            }
        },
        onManualReplySent(payload) {
            const cid = payload && payload.conversation_id ? payload.conversation_id : '';
            this.showToast(cid ? ('Manual reply sent (FS #' + cid + ')') : 'Manual reply sent');
            this.manualReplyModalOpen = false;
            // Reload picks up both the Timeline entry and the reply itself:
            // the send endpoint syncs it into `## Replies` before responding.
            this.load();
        },
        /**
         * Pick the right "blocks reply / non-blocking" badge for a subtask:
         *   - blocks_reply:false  → muted "non-blocking" pill (info)
         *   - blocks_reply:true + open → orange "blocks reply" (action needed)
         *   - blocks_reply:true + done → no badge (the [x] checkbox says it all)
         */
        subtaskBlockingBadge(s) {
            if (!s || s.blocks_reply === undefined) return null;
            if (s.blocks_reply === false) {
                return { label: 'non-blocking', cls: 'td-badge-nonblocking', title: 'blocks_reply: false — reply can proceed without this subtask' };
            }
            if (s.checked) return null;
            return { label: 'blocks reply', cls: 'td-badge-blocking', title: 'blocks_reply: true — reply waits on this subtask' };
        },
        openSubtask(s) {
            if (!s || !s.path) return;
            // s.path is `./FILENAME.md` (or `FILENAME.md`). Strip leading `./`.
            const filename = String(s.path).replace(/^\.\//, '');
            this.subtaskModalFilename = filename;
            this.subtaskModalTitle = s.title || '';
            this.subtaskModalOpen = true;
        },
        openRelated(t) {
            // Related tasks live on other lists (outside this ticket's directory),
            // so they load via the /related/{taskId} endpoint, which re-derives the
            // vetted related-task list server-side and returns that file's content.
            if (!t || !t.task_id) return;
            this.relatedModalUrl = '/api/tickets/' + encodeURIComponent(this.ticketId)
                + '/related/' + encodeURIComponent(t.task_id);
            this.relatedModalPath = t.path || '';
            this.relatedModalTitle = t.title || t.task_id;
            this.relatedModalOpen = true;
        },
        extractFirstEmail(s) {
            if (!s) return '';
            const m = String(s).match(/<([^>]+@[^>]+)>/);
            if (m) return m[1].trim().toLowerCase();
            const m2 = String(s).match(/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/);
            return m2 ? m2[1].trim().toLowerCase() : '';
        },
        extractAllEmails(s) {
            if (!s) return [];
            const matches = String(s).match(/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/g);
            if (!matches) return [];
            const seen = new Set();
            const out = [];
            for (const m of matches) {
                const e = m.trim().toLowerCase();
                if (!seen.has(e)) { seen.add(e); out.push(e); }
            }
            return out;
        },
        onRejectRequested() {
            this.sendModalOpen = false;
            this.$nextTick(() => {
                this.rejectModalOpen = true;
            });
        },
        formatDraftDate(value) {
            if (!value) return '';
            // The backend returns either a canonical UTC string from the draft's
            // frontmatter ("2026-05-13 11:30 UTC") or a normalised UTC fallback
            // in the same shape. Both go through the same converter as every
            // other stamp in the panel, so a draft's time reads the same way as
            // the reply timestamps next to it.
            return this.toLocalTime(value);
        },
        onRejected() {
            this.showToast('Draft sent back to Reply Drafting');
            // Refresh ticket data so the updated metadata/timeline shows.
            this.load();
        },
        showToast(msg) {
            this.toastMessage = msg;
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => {
                this.toastMessage = '';
                this.toastTimer = null;
            }, 4000);
        },
        replyClass(r) {
            const role = (r.role || '').toLowerCase();
            const type = (r.type || '').toLowerCase();
            if (role === 'agent' && type === 'draft proposal') return 'td-reply-draft';
            if (role === 'agent' && type === 'private note')   return 'td-reply-private';
            if (role === 'agent' && type === 'public reply')   return 'td-reply-agent-public';
            return 'td-reply-default';
        },
        splitParagraphs(body) {
            if (!body) return [];
            // Preserve paragraph breaks (blank line separated). Within a paragraph,
            // collapse to a single string — line wrapping handled by CSS.
            return body
                .replace(/\r\n/g, '\n')
                .split(/\n\s*\n/)
                .map(p => p.trim())
                .filter(p => p.length > 0);
        },
        /**
         * Segment a body / reply text into renderable chunks:
         *   - `[inline image: NAME]` token → { type:'image', filename }
         *   - `**Attachments:** file — note, file — note` line →
         *     { type:'attachments', items:[{filename, note}, ...] } (with
         *     inline-* entries deduped against any image segments that
         *     already appeared in the body)
         *   - everything else → { type:'text', value:str } (text paragraph)
         */
        renderSegments(body) {
            if (!body) return [];
            const text = body.replace(/\r\n/g, '\n');
            const rawSegments = this.buildRawSegments(text);
            return this.foldSignature(rawSegments);
        },
        buildRawSegments(text) {
            const segments = [];
            const inlinedFilenames = new Set();

            // First pass: split body by paragraph (blank-line separated).
            const paragraphs = text.split(/\n\s*\n/);
            for (const para of paragraphs) {
                const trimmed = para.trim();
                if (!trimmed) continue;

                // Attachments line: `**Attachments:** ...`
                if (/^\*\*Attachments:\*\*/i.test(trimmed)) {
                    const list = trimmed.replace(/^\*\*Attachments:\*\*\s*/i, '');
                    if (/^_?\(none\)_?$/i.test(list.trim())) continue;
                    const items = list.split(',').map(s => {
                        const part = s.trim();
                        // Format: `filename.ext — note` or `filename.ext`
                        const m = part.match(/^([A-Za-z0-9._\-]+\.[A-Za-z0-9]+)\s*(?:[—-]\s*(.*))?$/);
                        if (m) return { filename: m[1], note: (m[2] || '').trim() };
                        return null;
                    }).filter(Boolean)
                      .filter(it => !inlinedFilenames.has(it.filename));
                    if (items.length) segments.push({ type: 'attachments', items });
                    continue;
                }

                // Inline-image-only paragraphs (one or more `[inline image: NAME]` tokens,
                // possibly separated by whitespace/newlines).
                const onlyImages = trimmed.split(/\n+/).every(line => /^\[inline image:\s*[A-Za-z0-9._\-]+\]\s*$/i.test(line.trim()) || line.trim() === '');
                if (onlyImages && /\[inline image:/i.test(trimmed)) {
                    for (const line of trimmed.split(/\n+/)) {
                        const m = line.trim().match(/^\[inline image:\s*([A-Za-z0-9._\-]+)\]\s*$/i);
                        if (m) {
                            segments.push({ type: 'image', filename: m[1] });
                            inlinedFilenames.add(m[1]);
                        }
                    }
                    continue;
                }

                // Mixed paragraph: text containing inline-image tokens. Split.
                if (/\[inline image:/i.test(trimmed)) {
                    const parts = trimmed.split(/(\[inline image:\s*[A-Za-z0-9._\-]+\])/i);
                    let buf = '';
                    for (const part of parts) {
                        const m = part.match(/^\[inline image:\s*([A-Za-z0-9._\-]+)\]$/i);
                        if (m) {
                            if (buf.trim()) segments.push({ type: 'text', value: buf.trim() });
                            buf = '';
                            segments.push({ type: 'image', filename: m[1] });
                            inlinedFilenames.add(m[1]);
                        } else {
                            buf += part;
                        }
                    }
                    if (buf.trim()) segments.push({ type: 'text', value: buf.trim() });
                    continue;
                }

                // Plain text paragraph.
                segments.push({ type: 'text', value: trimmed });
            }
            return segments;
        },
        segmentClass(seg) {
            if (seg.type === 'text') return 'td-body-para';
            if (seg.type === 'image') return 'td-body-imgrow';
            if (seg.type === 'attachments') return 'td-attach-block';
            return '';
        },
        /**
         * Detect signature start (Regards, Cheers, Pozdrawiam, --- etc.) and
         * wrap everything from that index onward in a single 'signature'
         * segment so the template can render it inside a `<details>`.
         */
        foldSignature(segments) {
            const sigPatterns = [
                /^(best\s+|kind\s+|warm\s+|with\s+)?regards[,.\s]*$/i,
                /^cheers[,.\s]*$/i,
                /^thanks(?:\s+(?:a\s+lot|so\s+much|in\s+advance))?[,.\s]*$/i,
                /^thank\s+you[,.\s]*$/i,
                /^(many\s+)?thanks(?:\s+again)?[,.\s]*$/i,
                /^best[,.\s]*$/i,
                /^sincerely(\s+yours)?[,.\s]*$/i,
                /^yours(\s+sincerely|\s+truly|\s+faithfully)?[,.\s]*$/i,
                /^pozdrawiam(\s+serdecznie)?[,.\s]*$/i,
                /^z\s+powa[zż]aniem[,.\s]*$/i,
                /^z\s+wyrazami(\s+szacunku)?[,.\s]*$/i,
                /^pozdrowienia[,.\s]*$/i,
                /^--\s*$/,
                /^—\s*$/,
                /^__+\s*$/,
            ];
            let cutoff = -1;
            for (let i = 0; i < segments.length; i++) {
                const s = segments[i];
                if (s.type !== 'text') continue;
                const t = String(s.value || '').replace(/\s+/g, ' ').trim();
                if (!t) continue;
                if (sigPatterns.some((re) => re.test(t))) cutoff = i;
            }
            if (cutoff < 0 || cutoff >= segments.length - 1) return segments;
            const head = segments.slice(0, cutoff);
            const sig  = segments.slice(cutoff);
            head.push({ type: 'signature', children: sig });
            return head;
        },
        attachmentUrl(filename) {
            const base = window.__API_BASE || '';
            return base + '/api/tickets/' + encodeURIComponent(this.ticketId)
                + '/attachment/' + encodeURIComponent(filename);
        },
        // Server-enriched HTML (description / reply bodies) carries inline
        // `<img src="/api/tickets/.../attachment/...">`. The browser loads those
        // directly, bypassing the fetch() base-path shim, so under a
        // subdirectory mount (e.g. /www/tickets) they escape the app root and
        // 404. Prefix the app base onto every /api/ src+href before rendering.
        withApiBase(html) {
            const base = window.__API_BASE || '';
            if (!html || !base) return html;
            return String(html).replace(/(src|href)="\/api\//g, '$1="' + base + '/api/');
        },
        isImage(filename) {
            return /\.(png|jpe?g|gif|webp|svg)$/i.test(filename || '');
        },
    },
};
</script>

<style scoped>
.td-labels {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    border-bottom: 1px solid #21262d;
}
.td-labels-caption { font-size: 0.72em; color: #8b949e; margin-right: 2px; }
.td-labels-none { font-size: 0.72em; color: #6e7681; font-style: italic; }
.td-labels-add {
    margin-left: auto;
    background: #1f6feb; border: 1px solid #1f6feb; color: #fff;
    padding: 3px 10px; border-radius: 6px; cursor: pointer; font-size: 0.72em;
}
.td-labels-add:hover { background: #388bfd; border-color: #388bfd; }
.td-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1000;
    display: flex;
    justify-content: center;
    padding: 12px;
}
.td-panel {
    /* Panel BG is now LIGHTER than the section cards, so sections appear
       as darker "floating" cards against the page surface. This flip
       gives much higher contrast at the section boundary — the eye reads
       each section as a discrete object, not as a slightly-lifted patch. */
    width: 100%;
    max-width: 1440px;
    height: 100%;
    background: #414b62;
    color: #f0f3fa;
    border: 1px solid #5a6680;
    border-radius: 8px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.6);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.td-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    border-bottom: 1px solid #5a6680;
    background: #2c3548;
}
.td-header-title {
    display: flex;
    align-items: baseline;
    gap: 10px;
    min-width: 0;
}
.td-id {
    font-weight: 700;
    font-size: 15px;
    color: #88c0ff;
}
.td-path {
    font-size: 11px;
    color: #8b94a3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.td-stage {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    background: #21262d;
    color: #c9d1d9;
    border: 1px solid #30363d;
    white-space: nowrap;
}
.td-stage-new                          { background: #1a3a4d; color: #79c0ff; border-color: #1f6feb55; }
.td-stage-decomposition                { background: #2a1a4d; color: #bc8cff; border-color: #6e40c955; }
.td-stage-awaiting-subtasks            { background: #1a2a4d; color: #79c0ff; border-color: #1f6feb55; }
.td-stage-reply-drafting               { background: #2a2a1a; color: #d29922; border-color: #d2992255; }
.td-stage-security-check               { background: #4d3a1a; color: #f0b656; border-color: #d2992255; }
.td-stage-ready-to-send                { background: #1a4d2e; color: #56d364; border-color: #2ea04355; }
.td-stage-replied-awaiting-customer    { background: #1a4d3a; color: #56d364; border-color: #2ea04355; }
.td-stage-customer-replied             { background: #2a4d1a; color: #a5d75c; border-color: #56d36455; }
.td-stage-human-review                 { background: #4d2a1a; color: #ff7b72; border-color: #f8514955; }
.td-stage-informational                { background: #1a3a4d; color: #58a6ff; border-color: #1f6feb55; }
.td-stage-notification                 { background: #1a3a4d; color: #58a6ff; border-color: #1f6feb55; }
.td-stage-spam                         { background: #2d1318; color: #ff7b72; border-color: #f8514955; }
.td-stage-closed                       { background: #3d1a1a; color: #f85149; border-color: #f8514955; }
.td-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.td-close:hover { color: #c9d1d9; }

.td-body {
    flex: 1;
    overflow-y: auto;
    padding: 18px 22px 28px;
    line-height: 1.5;
}

/* ─── Subtle divider between technical/supporting block and conversation.
   NO column split — single column stays. Thin amber rule with the word
   "Conversation" inline; eye reads "above = support, below = the thread"
   without losing full width for the content. */
.td-group-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 14px 0 18px;
    color: #ffc857;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}
.td-group-divider::before,
.td-group-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, rgba(255, 200, 87, 0.05), rgba(255, 200, 87, 0.45));
}
.td-group-divider::after {
    background: linear-gradient(to right, rgba(255, 200, 87, 0.45), rgba(255, 200, 87, 0.05));
}
.td-group-divider-label { padding: 0 6px; white-space: nowrap; }

.td-section {
    /* Cards are DARKER than the lighter panel BG so they appear as
       distinct, well-defined surfaces. Strong border + small drop
       shadow further pop them off the page. */
    margin-bottom: 18px;
    background: #232c3d;
    border: 1px solid #5a6680;
    border-radius: 8px;
    padding: 18px 22px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}
/* Tier classes still in template; deliberately empty so the design stays
   flat. Re-add per-tier overrides only if a future change requires it. */
.td-tier-1, .td-tier-2, .td-tier-3 { /* no overrides */ }

/* ─── Compact disclosure section (Subtasks + Related Tasks above the
   Initial message). Same minimal look as the metadata Technical Details
   block — quiet by default, full content one click away. */
.td-section-workflow {
    padding: 14px 20px 16px;
}
.td-section-workflow .td-meta-tech-wrap {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #4a5670;
}
.td-section-workflow .td-meta-tech-wrap:first-of-type {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}

/* Legacy class still referenced by the (now-removed) standalone Timeline
   block. Left empty to avoid breaking any external reference. */
.td-section-timeline { }
.td-inline-timeline-toggle {
    background: transparent;
    border: 0;
    color: #8b94a3;
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    cursor: pointer;
    padding: 2px 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    text-align: left;
}
.td-inline-timeline-toggle:hover { color: #c9d1d9; }
.td-inline-timeline-count {
    color: #6e7681;
    font-weight: 500;
    font-size: 11px;
    text-transform: none;
    letter-spacing: 0;
    margin-left: auto;
}
.td-timeline-rich {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #2a3346;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    line-height: 1.5;
}
.td-timeline-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: baseline;
}
.td-timeline-date {
    color: #e1a700;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
    min-width: 168px;
}
.td-timeline-action {
    color: #e6edf3;
    flex: 1;
    min-width: 0;
    word-break: break-word;
}

.td-h2-sub {
    font-size: 12px;
    font-weight: 500;
    color: #8b94a3;
    text-transform: none;
    letter-spacing: 0;
    margin-left: 8px;
    font-style: italic;
}

/* ─── Star icon button in the detail header ───────────────────────── */
.td-star {
    background: transparent;
    border: 0;
    color: #6e7681;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
    transition: color 80ms ease, transform 80ms ease;
}
.td-star:hover { color: #d29922; transform: scale(1.08); }
.td-star-on { color: #ffd76b; }

/* Tenant-version status banner — top-of-detail callout when the ticket is
   parked waiting on a tenant upgrade (orchestrator's `awaiting_version:`
   flag) or when the watcher has just recorded the upgrade landed. Two
   colour variants matching the urgency. */
.td-version-banner {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 14px;
    border-radius: 6px;
    border: 1px solid;
    margin-bottom: 16px;
}
.td-version-awaiting {
    background: #2d2410;
    border-color: #d29922;
    color: #d29922;
}
.td-version-landed {
    background: #102d18;
    border-color: #3fb950;
    color: #3fb950;
}
.td-version-icon {
    font-size: 22px;
    line-height: 1;
    flex-shrink: 0;
}
.td-version-text {
    flex: 1;
    font-size: 12.5px;
    line-height: 1.45;
    color: #c9d1d9;
}
.td-version-headline {
    font-size: 13px;
    font-weight: 600;
    color: inherit;
    margin-bottom: 4px;
}
.td-version-awaiting .td-version-headline { color: #d29922; }
.td-version-landed .td-version-headline { color: #3fb950; }
.td-version-detail { color: #c9d1d9; }
.td-version-meta { color: #8b949e; }

.td-h2 {
    /* Section headers — the SINGLE accent colour on the page (amber gold).
       Same on every section so the eye treats "amber line + bottom rule"
       as "section starts here". No uppercase, no decoration tricks. */
    font-size: 16px;
    font-weight: 700;
    color: #ffc857;
    margin: 0 0 14px;
    padding: 0 0 8px;
    border-bottom: 1px solid #3f4a60;
    letter-spacing: 0.01em;
}
.td-h2-toggle {
    cursor: pointer;
    user-select: none;
}
.td-disclosure {
    display: inline-block;
    width: 14px;
    color: #8b949e;
}

.td-meta {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    font-size: 13px;
    margin: 0;
}
.td-meta dt {
    font-weight: 700;
    color: #a4adba;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.td-meta dd {
    margin: 0;
    color: #f0f3fa;
    word-break: break-word;
    font-weight: 500;
    font-size: 14px;
}
.td-meta a { color: #58a6ff; }

/* --- Metadata tiering -------------------------------------------------- */
.td-section-meta {
    /* Same card style as everything else — hierarchy from position + size,
       not from background. */
}
.td-meta-primary {
    /* Two columns on wide screens, one on narrow — keeps the panel scannable
       in a single eye-sweep without forcing horizontal scrolling. */
    grid-template-columns: max-content 1fr max-content 1fr;
    column-gap: 18px;
    row-gap: 6px;
}
@media (max-width: 980px) {
    .td-meta-primary { grid-template-columns: max-content 1fr; }
}

.td-meta-secondary {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed #3b4870;
}

.td-meta-tech-wrap { margin-top: 6px; padding-top: 6px; border-top: 1px dashed #3b4870; }
.td-meta-tech-toggle {
    background: transparent;
    border: 0;
    color: #b8c0cb;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.td-meta-tech-toggle:hover { color: #e6edf3; }
.td-meta-tech {
    margin-top: 8px;
    font-size: 12.5px;
    grid-template-columns: max-content 1fr;
}
.td-meta-tech dt { color: #8b94a3; font-weight: 600; font-size: 11.5px; }
.td-meta-tech dd { color: #b8c0cb; font-weight: 400; }

/* --- Soft-tier badges (Domain / Category / Priority) ------------------- */
.td-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.4;
    border: 1px solid transparent;
    letter-spacing: 0.01em;
    white-space: nowrap;
}
.td-badge-red    { background: rgba(248, 81, 73, 0.13); color: #f85149; border-color: rgba(248, 81, 73, 0.35); }
.td-badge-orange { background: rgba(255, 140, 0, 0.12); color: #ff9d3a; border-color: rgba(255, 140, 0, 0.35); }
.td-badge-yellow { background: rgba(210, 153, 34, 0.12); color: #d29922; border-color: rgba(210, 153, 34, 0.35); }
.td-badge-green  { background: rgba(63, 185, 80, 0.12); color: #3fb950; border-color: rgba(63, 185, 80, 0.35); }
.td-badge-blue   { background: rgba(56, 139, 253, 0.12); color: #58a6ff; border-color: rgba(56, 139, 253, 0.35); }
.td-badge-purple { background: rgba(163, 113, 247, 0.12); color: #a371f7; border-color: rgba(163, 113, 247, 0.35); }
.td-badge-teal   { background: rgba(57, 192, 184, 0.12); color: #39c0b8; border-color: rgba(57, 192, 184, 0.35); }
.td-badge-pink   { background: rgba(218, 75, 130, 0.12); color: #da4b82; border-color: rgba(218, 75, 130, 0.35); }
.td-badge-amber  { background: rgba(225, 167, 0, 0.12); color: #e1a700; border-color: rgba(225, 167, 0, 0.35); }
.td-badge-neutral { background: rgba(139, 148, 158, 0.12); color: #8b949e; border-color: rgba(139, 148, 158, 0.35); }
.td-badge-soft   { background: rgba(110, 118, 129, 0.10); color: #c9d1d9; border-color: rgba(110, 118, 129, 0.30); }

/* --- Section H2 with INLINE toggle buttons next to the title ----------
   Toggle buttons have a clear ON state (amber fill, bold) vs OFF
   (transparent + muted border). The wording always says the CURRENT
   state, so the operator never has to guess which way is which. */
.td-h2-with-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.td-sort-toggle {
    background: transparent;
    border: 1px solid #5a6680;
    color: #a4adba;
    font: inherit;
    font-size: 12px;
    border-radius: 6px;
    padding: 4px 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
}
.td-sort-toggle:hover {
    background: #34405a;
    border-color: #8b94a3;
    color: #f0f3fa;
}
.td-sort-toggle-on {
    background: rgba(255, 200, 87, 0.18);
    border-color: #ffc857;
    color: #ffc857;
}
.td-sort-toggle-on:hover {
    background: rgba(255, 200, 87, 0.28);
}
.td-sort-arrow {
    font-size: 13px;
    line-height: 1;
}
.td-toggle-count {
    display: inline-block;
    background: rgba(255, 255, 255, 0.10);
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
}
.td-sort-toggle-on .td-toggle-count {
    background: rgba(255, 200, 87, 0.30);
    color: #ffd76b;
}

.td-subject {
    font-size: 16px;
    color: #f0f3fa;
    font-weight: 600;
}
.td-initial-label {
    /* Sub-section label inside the "Initial message" card. Amber to match
       the section header convention; smaller + uppercase so it doesn't
       compete with the actual subject/body text below it. */
    font-size: 11.5px;
    color: #ffc857;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin: 0 0 6px;
}
.td-initial-subject {
    font-size: 17px;
    color: #ffffff;
    font-weight: 700;
    margin-bottom: 18px;
    line-height: 1.4;
}
.td-section-initial .td-body-text {
    margin-top: 0;
}

/* Collapsible description body. When `td-initial-collapsed` is set, the
   body is clipped to ~6 lines with a fade overlay at the bottom hinting
   that more content lives below. The toggle button beneath the wrap
   flips state. */
.td-initial-body-wrap {
    position: relative;
}
.td-initial-collapsed {
    max-height: 9em;
    overflow: hidden;
}
.td-initial-collapsed::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3em;
    pointer-events: none;
    background: linear-gradient(to bottom, rgba(35, 44, 61, 0), #232c3d 90%);
    /* The end colour matches `.td-section` background so the fade dies in
       seamlessly into the card surface. */
}
.td-initial-toggle {
    margin: 12px auto 0;
    display: block;
    background: rgba(255, 200, 87, 0.10);
    border: 1px solid rgba(255, 200, 87, 0.40);
    color: #ffc857;
    font: inherit;
    font-size: 12.5px;
    font-weight: 600;
    border-radius: 6px;
    padding: 6px 18px;
    cursor: pointer;
}
.td-initial-toggle:hover {
    background: rgba(255, 200, 87, 0.20);
    border-color: rgba(255, 200, 87, 0.60);
}

.td-body-text {
    background: #1e2533;
    border: 1px solid #3f4a60;
    border-radius: 6px;
    padding: 16px 18px;
    color: #f0f3fa;
    font-size: 14px;
    line-height: 1.6;
}
.td-body-text > pre.td-body-para {
    margin: 0 0 10px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    white-space: pre-wrap;
    word-break: break-word;
}
.td-body-text > pre.td-body-para:last-child { margin-bottom: 0; }

.td-body-text > .td-body-imgrow,
.td-reply-img-wrap {
    margin: 6px 0;
}
.td-inline-img {
    max-width: 100%;
    max-height: 400px;
    border-radius: 4px;
    border: 1px solid #30363d;
    display: block;
    background: #0d1117;
}
/* Sanitised FS HTML for body / replies. Constrain images, preserve flow.
   EVERY rule targeting a descendant here MUST be wrapped in `:deep()`. This
   block styles markup injected with `v-html`, and injected nodes never get the
   scoped-style data attribute — so a plain `.td-html img` selector compiles to
   one that can never match, and the rule silently does nothing. That is how a
   customer's screenshot ended up rendering at its natural pixel width and
   overflowing the panel: `max-width: 100%` was written but never applied. */
.td-html { font-size: 13px; line-height: 1.5; color: #c9d1d9; word-break: break-word; }
.td-html :deep(p) { margin: 0 0 8px; }
.td-html :deep(p:last-child) { margin-bottom: 0; }
.td-html :deep(img) {
    max-width: 100%;
    height: auto;
    margin: 6px 0;
    border-radius: 4px;
    border: 1px solid #30363d;
    background: #0d1117;
    display: inline-block;
}
.td-html :deep(a) { color: #58a6ff; }
.td-html :deep(blockquote) {
    margin: 6px 0;
    padding: 4px 10px;
    border-left: 3px solid #30363d;
    color: #8b949e;
}
.td-html :deep(ul), .td-html :deep(ol) { margin: 6px 0 6px 20px; padding: 0; }
.td-html :deep(li) { margin: 2px 0; }
.td-html :deep(pre), .td-html :deep(code) {
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 4px;
    padding: 1px 5px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
}
.td-html :deep(pre) { padding: 8px 10px; overflow-x: auto; }
.td-html :deep(table) { border-collapse: collapse; margin: 6px 0; max-width: 100%; }
.td-html :deep(th), .td-html :deep(td) { border: 1px solid #30363d; padding: 4px 8px; }

/* Collapsed signature block (inside body or reply, html or text mode). */
.td-signature,
.td-html :deep(details.td-signature) {
    margin: 8px 0 0;
    border-top: 1px dashed #30363d;
    padding-top: 6px;
}
.td-signature-summary,
.td-html :deep(summary.td-signature-summary) {
    cursor: pointer;
    font-size: 11px;
    color: #6e7681;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    list-style: none;
    padding: 2px 0;
    user-select: none;
}
.td-signature-summary::-webkit-details-marker,
.td-html :deep(summary.td-signature-summary::-webkit-details-marker) { display: none; }
.td-signature-summary::before,
.td-html :deep(summary.td-signature-summary::before) {
    content: '▸';
    display: inline-block;
    margin-right: 6px;
    transition: transform 0.12s;
}
.td-signature[open] > .td-signature-summary::before,
.td-html :deep(details.td-signature[open] > summary.td-signature-summary::before) {
    transform: rotate(90deg);
}
.td-signature-summary:hover,
.td-html :deep(summary.td-signature-summary:hover) { color: #c9d1d9; }
/* Slightly muted signature content. */
.td-signature > *:not(summary),
.td-html :deep(details.td-signature > *:not(summary)) {
    opacity: 0.75;
    font-size: 11.5px;
}
.td-attach-block { margin: 8px 0 4px; }
.td-attach-label {
    font-size: 11.5px;
    color: #8b949e;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 4px;
}
.td-attach-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.td-attach-tile {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 4px;
    overflow: hidden;
    text-decoration: none;
    color: #79c0ff;
    font-size: 11px;
    padding: 4px;
    box-sizing: border-box;
}
.td-attach-tile:hover { border-color: #1f6feb; }
.td-attach-tile img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}
.td-attach-file {
    text-align: center;
    word-break: break-word;
    line-height: 1.2;
}

.td-reply {
    background: #2e384d;
    border: 1px solid #4a5670;
    border-radius: 7px;
    margin-bottom: 10px;
    overflow: hidden;
    /* Compact reply cards — tight padding, small gaps between cards.
       Readability stays high via type weight + colour contrast, not via
       excess whitespace. */
}
/* Per-kind tints — customer keeps the blueish slate it had; agent public
   replies switch to a near-white card so OUR sent messages pop visually
   like a sticky note (and they're the message types that need to read
   cleanest because operators re-read them before sending follow-ups). */
/* All reply cards stay in the same DARK family. Only the HUE shifts subtly
   between kinds — same lightness, just enough to give the eye a "different
   speaker / kind" hint without breaking the uniform dark surface. */
.td-reply-kind-customer { background: #243a4d; border-color: #355d7a; } /* slight blue */
.td-reply-kind-public   { background: #283f3a; border-color: #3a5c52; } /* slight green */
.td-reply-kind-private  { background: #322c3a; border-color: #4a4256; } /* slight purple */
.td-reply-kind-draft    { background: #3a3322; border-color: #5a4f30; } /* slight amber */
.td-reply-kind-default  { background: #2e384d; border-color: #4a5670; }
.td-reply-strip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-bottom: 1px solid #4a5670;
}
.td-reply-kind  { }
.td-reply-index { font-weight: 600; opacity: 0.7; }
/* Kind tints — semantic, not decorative. */
.td-reply-strip-customer { background: #1f3a4d; color: #79d2ff; }   /* customer wrote in */
.td-reply-strip-public   { background: #1a3f2e; color: #6cd97c; }   /* agent sent it to the customer */
.td-reply-strip-private  { background: #3a3340; color: #c0b2d1; }   /* internal audit / system */
.td-reply-strip-draft    { background: #443618; color: #ffc857; }   /* draft proposal, not sent */
.td-reply-strip-default  { background: #2c3850; color: #a4adba; }

.td-reply-header {
    padding: 8px 12px;
    border-bottom: 1px solid #4a5670;
    background: rgba(0, 0, 0, 0.18);
}
.td-reply-author-line { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.td-reply-author      { color: #ffffff; font-weight: 700; font-size: 14px; }
.td-reply-email       { color: #a4adba; font-weight: 400; font-size: 12px; }
.td-reply-meta-line   { margin-top: 2px; color: #a4adba; font-size: 11.5px; }
.td-reply-timestamp   { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #c0c9d6; }
.td-reply-body {
    padding: 10px 12px;
}
.td-reply-body p {
    margin: 0 0 8px;
    font-size: 13.5px;
    line-height: 1.5;
    white-space: pre-wrap;
    color: #f0f3fa;
}
.td-reply-body p:last-child { margin-bottom: 0; }
/* The old "background per kind on the whole card" approach is replaced by
   the top-strip + uniform card background — easier on the eye, kind
   communicated in one place. */
.td-reply-default       { }
.td-reply-agent-public  { }
.td-reply-private       { opacity: 0.92; }
.td-reply-draft         { background: #2a230e; border-color: #5a4a1a; }

.td-subtasks {
    list-style: none;
    padding: 0;
    margin: 0;
}
.td-subtask {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    font-size: 13px;
}
.td-checkbox {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    color: #8b949e;
}
.td-subtask-title {
    color: #58a6ff;
    text-decoration: none;
    flex: 1;
}
.td-subtask-title:hover { text-decoration: underline; }
.td-badge {
    background: #21262d;
    color: #8b949e;
    border-radius: 10px;
    padding: 1px 8px;
    font-size: 11px;
    margin-left: 2px;
}
.td-badge-blocking {
    background: #3a2317;
    color: #ffa657;
    border: 1px solid #d2992255;
}
.td-badge-nonblocking {
    background: #1c2128;
    color: #6e7681;
    border: 1px solid #30363d;
    font-style: italic;
}
.td-badge-status {
    background: #182234;
    color: #79c0ff;
    border: 1px solid #1f6feb55;
    text-transform: lowercase;
}

.td-related-tasks {
    list-style: none;
    padding: 0;
    margin: 0;
}
.td-related-task {
    display: flex;
    align-items: baseline;
    gap: 6px;
    padding: 4px 6px;
    border-bottom: 1px dashed #21262d;
    font-size: 12.5px;
    line-height: 1.4;
    flex-wrap: wrap;
}
.td-related-task:last-child { border-bottom: 0; }
.td-related-id {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 700;
    color: #79c0ff;
    flex-shrink: 0;
}
.td-related-title {
    color: #c9d1d9;
    flex: 1;
    min-width: 0;
    word-break: break-word;
}
.td-related-title-link {
    color: #58a6ff;
    text-decoration: none;
    cursor: pointer;
}
.td-related-title-link:hover { text-decoration: underline; }
.td-related-list {
    background: #2c3548;
    color: #ffffff;
    border: 1px solid #5a6680;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    flex-shrink: 0;
}
.td-related-list-name {
    color: #ffffff;
    font-weight: 700;
}
.td-related-list-sep {
    color: #8b94a3;
    margin: 0 2px;
}
.td-related-list-section {
    color: #c0c9d6;
    font-weight: 400;
}

.td-timeline {
    background: #131a26;
    border: 1px solid #2f3a52;
    border-radius: 6px;
    padding: 12px 14px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    color: #d0d6e0;
    white-space: pre-wrap;
    margin: 0;
    max-height: 360px;
    overflow-y: auto;
}

.td-empty {
    padding: 20px;
    color: #6e7681;
    text-align: center;
}
.td-empty.td-error { color: #f85149; }

.btn {
    font-size: 13px;
    padding: 6px 12px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-primary {
    background: #1f6feb;
    color: #fff;
    border-color: #1f6feb;
}
.btn-primary:hover:not(:disabled) {
    background: #388bfd;
}
.btn-sent {
    background: #1a3d2e;
    color: #56d364;
    border-color: #2ea04340;
}
.btn-sent:hover:not(:disabled) {
    background: #21513b;
    border-color: #2ea043;
}
.td-draft-btn {
    font-weight: 600;
}
.td-draft-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.td-auto-send-badge {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 6px;
    background: #1a3a1a;
    color: #56d364;
    border: 1px solid #2ea04380;
    white-space: nowrap;
}
.td-auto-send-icon {
    margin-right: 2px;
}
.td-draft-meta {
    margin-top: 6px;
    font-size: 12px;
    color: #6e7681;
}

.td-toast {
    margin: 8px 14px 0;
    padding: 8px 12px;
    background: #102a1c;
    border: 1px solid #2ea04355;
    border-radius: 6px;
    color: #3fb950;
    font-size: 12.5px;
}
.agent-request-banner {
    margin: 8px 14px;
    padding: 10px 12px;
    border: 1px solid #30363d;
    border-radius: 8px;
    background: #161b22;
    font-size: 13px;
    line-height: 1.45;
}
.agent-request-banner__head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.agent-request-banner__badge {
    font-weight: 600;
    font-size: 12.5px;
    padding: 3px 8px;
    border-radius: 4px;
    background: #21262d;
    color: #c9d1d9;
}
.agent-request-banner__elapsed {
    font-size: 12px;
    color: #8b949e;
}
.agent-request-banner__refresh {
    margin-left: auto;
    background: transparent;
    color: #8b949e;
    border: 1px solid #30363d;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 12px;
    cursor: pointer;
}
.agent-request-banner__refresh:hover:not(:disabled) {
    color: #c9d1d9;
    border-color: #6e7681;
}
.agent-request-banner__instruction,
.agent-request-banner__activity {
    color: #c9d1d9;
}
.agent-request-banner__activity {
    margin-top: 4px;
    font-size: 12px;
    color: #8b949e;
}
.agent-request-banner--queued {
    border-color: #d2992288;
    background: linear-gradient(180deg, #2d2113 0%, #161b22 100%);
}
.agent-request-banner--queued .agent-request-banner__badge {
    background: #3a2d12;
    color: #ffc857;
}
.agent-request-banner--in-flight {
    border-color: #1f6feb88;
    background: linear-gradient(180deg, #0f1b2e 0%, #161b22 100%);
}
.agent-request-banner--in-flight .agent-request-banner__badge {
    background: #142a4d;
    color: #58a6ff;
}
.agent-request-banner--done {
    border-color: #2ea04388;
    background: linear-gradient(180deg, #102a1c 0%, #161b22 100%);
}
.agent-request-banner--done .agent-request-banner__badge {
    background: #122a1a;
    color: #3fb950;
}
.agent-request-banner--error {
    border-color: #f8514988;
    background: linear-gradient(180deg, #2a1414 0%, #161b22 100%);
}
.agent-request-banner--error .agent-request-banner__badge {
    background: #4a1d1d;
    color: #f85149;
}
/* Ticket-level attachments (the `## Attachments` list) */
.td-tatt {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed #3d4757;
}
.td-tatt-label {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #ffc857;
    margin-bottom: 6px;
}
.td-tatt-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.td-tatt-item {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.td-tatt-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 10px;
    background: #1b2436;
    border: 1px solid #3d4757;
    border-radius: 6px;
    text-decoration: none;
    color: #79c0ff;
    font-size: 13.5px;
    font-weight: 500;
}
.td-tatt-link:hover {
    background: #22304a;
    border-color: #58a6ff;
}
.td-tatt-dead {
    color: #8b949e;
    cursor: default;
}
.td-tatt-dead:hover {
    background: #1b2436;
    border-color: #3d4757;
}
.td-tatt-icon { font-size: 14px; }
.td-tatt-name { word-break: break-all; }
.td-tatt-tag {
    font-size: 11px;
    color: #8b949e;
}
.td-tatt-tag-bad { color: #ffa198; }

.td-opnote {
    /* Muted amber card — the accent says "internal, only you see this". The
       tint stays low-saturation so it frames the note without competing with
       it for attention; the readable surface is the textarea inside. */
    background: #1f1c11;
    border: 1px solid #5c4818;
    border-radius: 8px;
    padding: 10px 12px;
}
.td-opnote-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 2px;
}
.td-opnote-label {
    /* Deliberately small and dim: the note below is the content, this is just
       its tag. Shrunk to a caption so it stops competing with the note. */
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #b8912f;
}
.td-opnote-status {
    font-size: 12px;
    color: #8b949e;
}
.td-opnote-save {
    margin-left: auto;
    background: #21262d;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 4px;
    padding: 3px 12px;
    font-size: 12.5px;
    cursor: pointer;
}
.td-opnote-save:hover:not(:disabled) {
    background: #30363d;
    border-color: #6e7681;
}
.td-opnote-save:disabled {
    opacity: 0.5;
    cursor: default;
}
.td-opnote-text {
    width: 100%;
    box-sizing: border-box;
    /* This note is usually "what stage is this ticket at" — the thing you want
       to read the instant the panel opens. So it is styled as PROMINENT TEXT,
       not as a form field: no sunken dark input, no small print. It stays a
       real <textarea> (click and type — no edit mode to enter), but the input
       chrome only shows up on hover/focus, when it is actually relevant. */
    background: transparent;
    color: #ffe9b8;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 6px 8px;
    font-size: 17px;
    font-weight: 500;
    line-height: 1.5;
    font-family: inherit;
    resize: vertical;
    /* Height follows the content (see autosizeOperatorNote) instead of a fixed
       3 rows, so a one-line note isn't marooned in an empty box. */
    min-height: 32px;
    overflow-y: hidden;
    cursor: text;
}
.td-opnote-text:hover {
    border-color: #5c4818;
    background: #17150d;
}
.td-opnote-text::placeholder {
    color: #8b7a4d;
    font-size: 13px;
    font-weight: 400;
}
.td-opnote-text:focus {
    outline: none;
    border-color: #d29922;
    background: #0d1117;   /* editing → show the input surface */
}

/* Internal-note-to-FreshService composer. Teal accent (vs the amber local
   scratchpad above) says "this one leaves the dashboard — it lands in FS too". */
.td-fsnote {
    background: #0e1a1c;
    border: 1px solid #1c4a52;
    border-radius: 8px;
    padding: 10px 12px;
}
.td-fsnote-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.td-fsnote-label {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #3fb6c4;
}
.td-fsnote-status { font-size: 12px; color: #8b949e; }
.td-fsnote-add {
    margin-left: auto;
    background: #14343a;
    color: #a5e3ec;
    border: 1px solid #1c4a52;
    border-radius: 4px;
    padding: 3px 12px;
    font-size: 12.5px;
    cursor: pointer;
}
.td-fsnote-add:hover:not(:disabled) { background: #1c4a52; border-color: #3fb6c4; }
.td-fsnote-add:disabled { opacity: 0.5; cursor: default; }
.td-fsnote-text {
    width: 100%;
    box-sizing: border-box;
    background: #0d1117;
    color: #c9d1d9;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 6px 8px;
    font-size: 13px;
    line-height: 1.5;
    font-family: inherit;
    resize: vertical;
    min-height: 44px;
}
.td-fsnote-text::placeholder { color: #6e7681; }
.td-fsnote-text:focus { outline: none; border-color: #3fb6c4; }
</style>
