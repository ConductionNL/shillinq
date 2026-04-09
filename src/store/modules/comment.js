// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useCommentStore = defineStore('comment', {
	state: () => ({
		comments: [],
		loading: false,
	}),
	getters: {
		byTarget: (state) => (targetType, targetId) => {
			return state.comments.filter(c => c.targetType === targetType && c.targetId === targetId)
		},
		unresolvedCount: (state) => (targetType, targetId) => {
			return state.comments.filter(c => c.targetType === targetType && c.targetId === targetId && !c.resolved).length
		},
	},
	actions: {
		async fetchComments(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/comments?targetType=${targetType}&targetId=${targetId}`)
				const response = await fetch(url, { headers: { requesttoken: OC.requestToken } })
				if (response.ok) {
					const data = await response.json()
					// Merge, don't replace all
					const otherComments = this.comments.filter(c => !(c.targetType === targetType && c.targetId === targetId))
					this.comments = [...otherComments, ...(data.results || data)]
				}
			} catch (error) {
				console.error('Failed to fetch comments:', error)
			} finally {
				this.loading = false
			}
		},
		async createComment(comment) {
			// POST
			const url = generateUrl('/apps/shillinq/api/v1/comments')
			const response = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
				body: JSON.stringify(comment),
			})
			if (response.ok) {
				const data = await response.json()
				this.comments.push(data)
				return data
			}
			throw new Error('Failed to create comment')
		},
		async resolveComment(id) {
			const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}/resolve`)
			const response = await fetch(url, {
				method: 'PATCH',
				headers: { requesttoken: OC.requestToken },
			})
			if (response.ok) {
				const data = await response.json()
				const idx = this.comments.findIndex(c => c.id === id)
				if (idx !== -1) this.comments.splice(idx, 1, data)
				return data
			}
		},
		async deleteComment(id) {
			const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}`)
			const response = await fetch(url, {
				method: 'DELETE',
				headers: { requesttoken: OC.requestToken },
			})
			if (response.ok) {
				this.comments = this.comments.filter(c => c.id !== id)
			}
		},
	},
})
