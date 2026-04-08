// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for Comment objects.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-3.1
 */
export const useCommentStore = defineStore('comment', {
	state: () => ({
		comments: [],
		loading: false,
	}),

	getters: {
		/**
		 * Filter comments by target.
		 *
		 * @param {object} state - Store state
		 * @return {Function} Filter function accepting targetType and targetId
		 */
		byTarget: (state) => (targetType, targetId) => {
			return state.comments.filter(
				(c) => c.targetType === targetType && c.targetId === targetId,
			)
		},

		/**
		 * Count unresolved comments.
		 *
		 * @param {object} state - Store state
		 * @return {number} Number of unresolved comments
		 */
		unresolvedCount: (state) => {
			return state.comments.filter((c) => !c.resolved).length
		},
	},

	actions: {
		/**
		 * Fetch comments for a specific target.
		 *
		 * @param {string} targetType - Entity type
		 * @param {string} targetId - Object ID
		 * @return {Promise<Array>} Comments list
		 */
		async fetchByTarget(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/comments')
					+ `?targetType=${encodeURIComponent(targetType)}&targetId=${encodeURIComponent(targetId)}`
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.comments = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch comments:', error)
			} finally {
				this.loading = false
			}
			return this.comments
		},

		/**
		 * Create a new comment.
		 *
		 * @param {object} data - Comment data { content, targetType, targetId }
		 * @return {Promise<object|null>} Created comment or null
		 */
		async createComment(data) {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/comments')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					const comment = await response.json()
					this.comments.push(comment)
					return comment
				}
			} catch (error) {
				console.error('Failed to create comment:', error)
			}
			return null
		},

		/**
		 * Resolve a comment.
		 *
		 * @param {string} id - Comment ID
		 * @return {Promise<object|null>} Resolved comment or null
		 */
		async resolveComment(id) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}/resolve`)
				const response = await fetch(url, {
					method: 'PATCH',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const updated = await response.json()
					const idx = this.comments.findIndex((c) => c.id === id)
					if (idx !== -1) {
						this.comments[idx] = updated
					}
					return updated
				}
			} catch (error) {
				console.error('Failed to resolve comment:', error)
			}
			return null
		},

		/**
		 * Delete a comment.
		 *
		 * @param {string} id - Comment ID
		 * @return {Promise<boolean>} Success status
		 */
		async deleteComment(id) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.comments = this.comments.filter((c) => c.id !== id)
					return true
				}
			} catch (error) {
				console.error('Failed to delete comment:', error)
			}
			return false
		},
	},
})
